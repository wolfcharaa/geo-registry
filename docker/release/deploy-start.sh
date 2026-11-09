#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)

# shellcheck disable=SC1091
. "${script_dir}/release-lib.sh"
# shellcheck disable=SC1091
. "${script_dir}/deployment-lib.sh"

if [ "$(id -u)" -eq 0 ] || [ -n "${SUDO_USER:-}" ]; then
    release_fail "run as the deployment user, without sudo"
fi

secrets_file=$(release_resolve_path "${COMPOSE_ENV_FILE:-config/prod.secrets.env}")
source_env_file=$(release_resolve_path \
    "${RELEASE_ENV_FILE:-docker/compose/prod/release.env}")
release_require_file "${secrets_file}"
release_require_file "${source_env_file}"

release_version=$(release_env_value RELEASE_VERSION "${source_env_file}" || true)
release_validate_version "${release_version}"
platform=${DOCKER_PLATFORM:-linux/amd64}
release_validate_platform "${platform}"
artifact_directory=$(release_resolve_path \
    "${RELEASE_ARTIFACT_DIR:-docker/data/release/${release_version}}")
identity_file=$(release_identity_file "${source_env_file}" "${artifact_directory}")
release_require_file "${identity_file}"

deployment_require_confirmed_target \
    deploy-start core activate "${identity_file}"
deployment_acquire_target_lock \
    deploy-start core activate "${identity_file}"

deployment_step=evidence-start
deployment_mutation_started=0
deployment_evidence_done=0
deployment_recovery=manual-recovery
deployment_evidence_begin deploy-start core activate "${identity_file}"
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

deployment_evidence_update backup_status not-required-for-activation

echo "==> [1/8] Checking the configured HTTP host port before expensive data gates"
deployment_step=port-preflight
COMPOSE_ENV_FILE="${secrets_file}" \
RELEASE_ENV_FILE="${source_env_file}" \
RELEASE_ARTIFACT_ENV_FILE="${identity_file}" \
DEPLOY_PORT_NEXT_COMMAND="make deploy-start DOCKER_PLATFORM=${platform}" \
    "${script_dir}/deployment-port-preflight.sh"

echo "==> [2/8] Checking that PostgreSQL restore is inactive and physically valid"
deployment_step=data-readiness
COMPOSE_ENV_FILE="${secrets_file}" \
RELEASE_ENV_FILE="${source_env_file}" \
RELEASE_ARTIFACT_ENV_FILE="${identity_file}" \
DEPLOY_DATA_REQUIRE_READY=1 \
    "${script_dir}/deployment-data-status.sh"

echo "==> [3/8] Verifying the frozen application image"
deployment_step=artifact-verification
COMPOSE_ENV_FILE="${secrets_file}" \
RELEASE_ENV_FILE="${source_env_file}" \
RELEASE_ARTIFACT_ENV_FILE="${identity_file}" \
RELEASE_ARTIFACT_DIR="${artifact_directory}" \
RELEASE_DRY_RUN=1 \
DOCKER_PLATFORM="${platform}" \
    "${script_dir}/change-release.sh"
deployment_evidence_update artifact_status verified

compose() {
    COMPOSE_ENV_FILE="${secrets_file}" \
    RELEASE_ENV_FILE="${source_env_file}" \
    RELEASE_ARTIFACT_ENV_FILE="${identity_file}" \
        "${project_root}/docker/compose/prod.sh" "$@"
}

echo "==> [4/8] Reading migration history from the restored database"
deployment_step=migration-history
compose run --rm --no-deps maintenance bin/app migrate:history
deployment_evidence_update migration_status history-checked

echo "==> [5/8] Running deep GAR integrity gates for regions 50 and 77"
deployment_step=gar-integrity
compose run --rm --no-deps maintenance \
    bin/app gar:integrity --regions=50,77 --deep --only-failed

echo "==> [6/8] Checking exact regional status and projection parity"
deployment_step=gar-status
compose run --rm --no-deps maintenance \
    bin/app gar:status --regions=50,77 --exact
deployment_evidence_update data_checks_status passed

echo "==> [7/8] Activating application and worker processes"
deployment_step=activation
deployment_mutation_started=1
deployment_evidence_update activation_status in-progress
COMPOSE_ENV_FILE="${secrets_file}" \
RELEASE_ENV_FILE="${source_env_file}" \
RELEASE_ARTIFACT_ENV_FILE="${identity_file}" \
RELEASE_ARTIFACT_DIR="${artifact_directory}" \
DOCKER_PLATFORM="${platform}" \
    "${script_dir}/change-release.sh"
deployment_evidence_update activation_status passed

echo "==> [8/8] Running HTTP smoke checks against the Compose host binding"
deployment_step=smoke
deployment_evidence_update smoke_status in-progress
COMPOSE_ENV_FILE="${secrets_file}" \
RELEASE_ENV_FILE="${source_env_file}" \
RELEASE_ARTIFACT_ENV_FILE="${identity_file}" \
    "${script_dir}/deployment-smoke.sh"
deployment_evidence_update smoke_status passed

deployment_step=evidence-finalize
deployment_evidence_finish desired-active
deployment_evidence_done=1

echo "Deployment start completed: ${release_version}."
