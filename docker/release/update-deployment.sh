#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)

# shellcheck disable=SC1091
. "${script_dir}/release-lib.sh"
# shellcheck disable=SC1091
. "${script_dir}/deployment-lib.sh"
# shellcheck disable=SC1091
. "${project_root}/docker/backup/backup-lib.sh"

for command_name in docker tr; do
    release_require_command "${command_name}"
done

if [ "$(id -u)" -eq 0 ] || [ -n "${SUDO_USER:-}" ]; then
    release_fail "run as the deployment user, without sudo"
fi

dry_run=${DEPLOY_UPDATE_DRY_RUN:-0}
assume_yes=0
for argument in "$@"; do
    case "${argument}" in
        --yes) assume_yes=1 ;;
        *) release_fail "usage: update-deployment.sh [--yes]" ;;
    esac
done
case "${dry_run}" in
    0|1) ;;
    *) release_fail "DEPLOY_UPDATE_DRY_RUN must be 0 or 1" ;;
esac

secrets_file=$(release_resolve_path "${COMPOSE_ENV_FILE:-config/prod.secrets.env}")
release_env_file=$(release_resolve_path "${RELEASE_ENV_FILE:-docker/compose/prod/release.env}")
release_require_file "${secrets_file}"
release_require_file "${release_env_file}"
release_version=$(release_env_value RELEASE_VERSION "${release_env_file}" || true)
release_validate_version "${release_version}"
platform=${DOCKER_PLATFORM:-linux/amd64}
release_validate_platform "${platform}"
artifact_dir=$(release_resolve_path "${RELEASE_ARTIFACT_DIR:-docker/data/release/${release_version}}")
identity_file=$(release_identity_file "${release_env_file}" "${artifact_dir}")
release_require_file "${identity_file}"

database_name=geo_registry
database_user=geo_registry
admin_user=postgres

deployment_evidence_active=0
if [ "${dry_run}" = 0 ]; then
    deployment_require_confirmed_target \
        deploy-update application update "${identity_file}"
    deployment_acquire_target_lock \
        deploy-update application update "${identity_file}"

    deployment_step=preflight
    deployment_mutation_started=0
    deployment_evidence_done=0
    deployment_recovery=manual-recovery
    deployment_evidence_begin deploy-update application update "${identity_file}"
    deployment_evidence_active=1
    deployment_evidence_on_exit() {
        deployment_exit_code=$1
        trap - EXIT HUP INT TERM
        if [ "${deployment_evidence_done}" != 1 ]; then
            deployment_failure_recovery=none
            if [ "${deployment_mutation_started}" = 1 ]; then
                deployment_failure_recovery=${deployment_recovery}
            fi
            deployment_evidence_fail "${deployment_exit_code}" \
                "${deployment_step}" "${deployment_mutation_started}" \
                "${deployment_failure_recovery}" || true
        fi
        exit "${deployment_exit_code}"
    }
    trap 'exit 129' HUP
    trap 'exit 130' INT
    trap 'exit 143' TERM
    trap 'deployment_evidence_on_exit $?' EXIT
fi

echo "Checking the configured HTTP host port before changing the running deployment."
deployment_step=port-preflight
COMPOSE_ENV_FILE="${secrets_file}" \
    RELEASE_ENV_FILE="${identity_file}" \
    DEPLOY_PORT_NEXT_COMMAND="make deploy-update DOCKER_PLATFORM=${platform}" \
    "${script_dir}/deployment-port-preflight.sh"

COMPOSE_ENV_FILE="${secrets_file}" \
    RELEASE_ENV_FILE="${release_env_file}" \
    RELEASE_DRY_RUN=1 \
    DOCKER_PLATFORM="${platform}" \
    RELEASE_ARTIFACT_DIR="${RELEASE_ARTIFACT_DIR:-}" \
    "${script_dir}/change-release.sh"
if [ "${deployment_evidence_active}" = 1 ]; then
    deployment_evidence_update artifact_status verified
fi

deployment_step=database-preflight
container=$(COMPOSE_ENV_FILE="${secrets_file}" RELEASE_ENV_FILE="${identity_file}" \
    "${project_root}/docker/compose/prod.sh" ps --quiet postgres)
backup_validate_container "${container}"

database_exists=$(docker exec "${container}" \
    psql --no-psqlrc --tuples-only --no-align --set=ON_ERROR_STOP=1 \
    --username "${admin_user}" --dbname postgres \
    --command "SELECT count(*) FROM pg_database WHERE datname = '${database_name}';" \
    | tr -d '[:space:]')
[ "${database_exists}" = 1 ] \
    || release_fail "managed database ${database_name} does not exist; use make data-restore or make data-init"

role_exists=$(docker exec "${container}" \
    psql --no-psqlrc --tuples-only --no-align --set=ON_ERROR_STOP=1 \
    --username "${admin_user}" --dbname postgres \
    --command "SELECT count(*) FROM pg_roles WHERE rolname = '${database_user}';" \
    | tr -d '[:space:]')
[ "${role_exists}" = 1 ] \
    || release_fail "managed database role does not exist: ${database_user}"

current_application_container=$(COMPOSE_ENV_FILE="${secrets_file}" \
    RELEASE_ENV_FILE="${identity_file}" \
    "${project_root}/docker/compose/prod.sh" ps --quiet app)
[ -n "${current_application_container}" ] \
    || release_fail "managed application container is not running; current deployment identity is unavailable"
current_application_source_oid=$(docker inspect \
    --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' \
    "${current_application_container}" 2>/dev/null || true)
release_validate_git_oid "${current_application_source_oid}"

backup_directory=${DEPLOY_BACKUP_DIR:-}
[ -n "${backup_directory}" ] \
    || release_fail "DEPLOY_BACKUP_DIR must select the verified backup of the current deployment"
backup_directory=$(release_resolve_path "${backup_directory}")
backup_validate_bundle "${backup_directory}"
backup_manifest="${backup_directory}/manifest.txt"
[ "$(backup_manifest_value component "${backup_manifest}" || true)" = application-postgres ] \
    || release_fail "deployment update backup must contain application-postgres"
[ "$(backup_manifest_value database_name "${backup_manifest}" || true)" = "${database_name}" ] \
    || release_fail "deployment update backup database does not match ${database_name}"
backup_application_revision=$(backup_manifest_value application_revision \
    "${backup_manifest}" || true)
[ "${backup_application_revision}" = "${current_application_source_oid}" ] \
    || release_fail "deployment update backup is not bound to the current application source OID"
backup_created_at=$(backup_manifest_value created_at "${backup_manifest}" || true)
[ -n "${backup_created_at}" ] \
    || release_fail "deployment update backup creation time is missing"

schema_compatibility=${DEPLOY_SCHEMA_COMPATIBILITY:-}
case "${schema_compatibility}" in
    none) schema_recovery=binary-rollback-allowed ;;
    backward-compatible) schema_recovery=binary-rollback-after-verification ;;
    expand-contract) schema_recovery=rollback-before-contract-only ;;
    forward-only) schema_recovery=roll-forward-or-approved-restore ;;
    *)
        release_fail "DEPLOY_SCHEMA_COMPATIBILITY must be none, backward-compatible, expand-contract or forward-only"
        ;;
esac
deployment_recovery=${schema_recovery}
if [ "${deployment_evidence_active}" = 1 ]; then
    deployment_evidence_update \
        backup_status verified \
        backup_directory "${backup_directory}" \
        backup_created_at "${backup_created_at}" \
        backup_application_revision "${backup_application_revision}" \
        schema_compatibility "${schema_compatibility}" \
        schema_recovery "${schema_recovery}"
fi

echo "Deployment update preflight passed."
echo "Release: ${release_version}"
echo "PostgreSQL container: ${container}"
echo "Database: ${database_name}"
echo "Current application source OID: ${current_application_source_oid}"
echo "Verified backup: ${backup_directory}"
echo "Backup created at: ${backup_created_at}"
echo "Schema compatibility: ${schema_compatibility}"
echo "Permitted recovery: ${schema_recovery}"
echo "The update stops producers/workers, applies migrations, runs data gates, activates the exact image and runs smoke."

if [ "${dry_run}" = 1 ]; then
    echo "Dry-run leaves containers, database and worker control state unchanged."
    exit 0
fi

deployment_step=operator-confirmation
if [ "${assume_yes}" -ne 1 ]; then
    [ -t 0 ] || release_fail "interactive confirmation is unavailable; rerun with DEPLOY_UPDATE_FLAGS=--yes"
    expected_confirmation="update:${release_version}:${database_name}"
    printf 'Type "%s" to update the deployment: ' "${expected_confirmation}"
    IFS= read -r confirmation
    [ "${confirmation}" = "${expected_confirmation}" ] \
        || release_fail "update confirmation did not match"
fi

compose() {
    COMPOSE_ENV_FILE="${secrets_file}" RELEASE_ENV_FILE="${identity_file}" \
        "${project_root}/docker/compose/prod.sh" "$@"
}

echo "Stopping HTTP producers before worker quiescence."
deployment_step=quiesce
deployment_mutation_started=1
deployment_evidence_update activation_status quiescing
compose stop app
compose run --rm --no-deps maintenance \
    bin/app worker:pause --worker-name=geo-registry-address --reason=deployment-update
compose run --rm --no-deps maintenance \
    bin/app worker:pause --worker-name=geo-registry-runtime --reason=deployment-update
compose stop worker-address worker-runtime
deployment_evidence_update activation_status quiesced

echo "Applying application migrations from ${release_version}."
deployment_step=migrations
deployment_evidence_update migration_status in-progress
compose run --rm --no-deps maintenance bin/app migrate:up --force-yes
deployment_evidence_update migration_status applied

echo "Running regional data gates before activation."
deployment_step=data-gates
deployment_evidence_update data_checks_status in-progress
compose run --rm --no-deps maintenance \
    bin/app gar:integrity --regions=50,77 --deep --only-failed
compose run --rm --no-deps maintenance \
    bin/app gar:status --regions=50,77 --exact
deployment_evidence_update data_checks_status passed

deployment_step=activation
deployment_evidence_update activation_status in-progress
COMPOSE_ENV_FILE="${secrets_file}" \
    RELEASE_ENV_FILE="${release_env_file}" \
    DEPLOY_PORT_NEXT_COMMAND="make deploy-update DOCKER_PLATFORM=${platform}" \
    DOCKER_PLATFORM="${platform}" \
    RELEASE_ARTIFACT_DIR="${RELEASE_ARTIFACT_DIR:-}" \
    "${script_dir}/change-release.sh"
deployment_evidence_update activation_status application-started

deployment_step=worker-resume
compose run --rm --no-deps maintenance \
    bin/app worker:resume --worker-name=geo-registry-address --reason=deployment-update
compose run --rm --no-deps maintenance \
    bin/app worker:resume --worker-name=geo-registry-runtime --reason=deployment-update
deployment_evidence_update activation_status passed

echo "Running HTTP smoke checks while the deployment target lock is held."
deployment_step=smoke
deployment_evidence_update smoke_status in-progress
COMPOSE_ENV_FILE="${secrets_file}" \
RELEASE_ENV_FILE="${release_env_file}" \
    "${script_dir}/deployment-smoke.sh"
deployment_evidence_update smoke_status passed

deployment_step=evidence-finalize
deployment_evidence_finish desired-active
deployment_evidence_done=1

echo "Deployment activation completed: ${release_version}."
