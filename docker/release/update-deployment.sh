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

echo "Checking the configured HTTP host port before changing the running deployment."
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

echo "Deployment update preflight passed."
echo "Release: ${release_version}"
echo "PostgreSQL container: ${container}"
echo "Database: ${database_name}"
echo "The update stops producers/workers, applies migrations, runs data gates, activates the exact image and runs smoke."

if [ "${dry_run}" = 1 ]; then
    echo "Dry-run leaves containers, database and worker control state unchanged."
    exit 0
fi

deployment_require_confirmed_target \
    deploy-update application update "${identity_file}"
deployment_acquire_target_lock \
    deploy-update application update "${identity_file}"

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
compose stop app
compose run --rm --no-deps maintenance \
    bin/app worker:pause --worker-name=geo-registry-address --reason=deployment-update
compose run --rm --no-deps maintenance \
    bin/app worker:pause --worker-name=geo-registry-runtime --reason=deployment-update
compose stop worker-address worker-runtime

echo "Applying application migrations from ${release_version}."
compose run --rm --no-deps maintenance bin/app migrate:up --force-yes

echo "Running regional data gates before activation."
compose run --rm --no-deps maintenance \
    bin/app gar:integrity --regions=50,77 --deep --only-failed
compose run --rm --no-deps maintenance \
    bin/app gar:status --regions=50,77 --exact

COMPOSE_ENV_FILE="${secrets_file}" \
    RELEASE_ENV_FILE="${release_env_file}" \
    DEPLOY_PORT_NEXT_COMMAND="make deploy-update DOCKER_PLATFORM=${platform}" \
    DOCKER_PLATFORM="${platform}" \
    RELEASE_ARTIFACT_DIR="${RELEASE_ARTIFACT_DIR:-}" \
    "${script_dir}/change-release.sh"

compose run --rm --no-deps maintenance \
    bin/app worker:resume --worker-name=geo-registry-address --reason=deployment-update
compose run --rm --no-deps maintenance \
    bin/app worker:resume --worker-name=geo-registry-runtime --reason=deployment-update

echo "Running HTTP smoke checks while the deployment target lock is held."
COMPOSE_ENV_FILE="${secrets_file}" \
RELEASE_ENV_FILE="${release_env_file}" \
    "${script_dir}/deployment-smoke.sh"

echo "Deployment activation completed: ${release_version}."
