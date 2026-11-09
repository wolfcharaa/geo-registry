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

echo "==> [1/8] Checking the configured HTTP host port before expensive data gates"
COMPOSE_ENV_FILE="${secrets_file}" \
RELEASE_ENV_FILE="${source_env_file}" \
RELEASE_ARTIFACT_ENV_FILE="${identity_file}" \
DEPLOY_PORT_NEXT_COMMAND="make deploy-start DOCKER_PLATFORM=${platform}" \
    "${script_dir}/deployment-port-preflight.sh"

echo "==> [2/8] Checking that PostgreSQL restore is inactive and physically valid"
COMPOSE_ENV_FILE="${secrets_file}" \
RELEASE_ENV_FILE="${source_env_file}" \
RELEASE_ARTIFACT_ENV_FILE="${identity_file}" \
DEPLOY_DATA_REQUIRE_READY=1 \
    "${script_dir}/deployment-data-status.sh"

echo "==> [3/8] Verifying the frozen application image"
COMPOSE_ENV_FILE="${secrets_file}" \
RELEASE_ENV_FILE="${source_env_file}" \
RELEASE_ARTIFACT_ENV_FILE="${identity_file}" \
RELEASE_ARTIFACT_DIR="${artifact_directory}" \
RELEASE_DRY_RUN=1 \
DOCKER_PLATFORM="${platform}" \
    "${script_dir}/change-release.sh"

compose() {
    COMPOSE_ENV_FILE="${secrets_file}" \
    RELEASE_ENV_FILE="${source_env_file}" \
    RELEASE_ARTIFACT_ENV_FILE="${identity_file}" \
        "${project_root}/docker/compose/prod.sh" "$@"
}

echo "==> [4/8] Reading migration history from the restored database"
compose run --rm --no-deps maintenance bin/app migrate:history

echo "==> [5/8] Running deep GAR integrity gates for regions 50 and 77"
compose run --rm --no-deps maintenance \
    bin/app gar:integrity --regions=50,77 --deep --only-failed

echo "==> [6/8] Checking exact regional status and projection parity"
compose run --rm --no-deps maintenance \
    bin/app gar:status --regions=50,77 --exact

echo "==> [7/8] Activating application and worker processes"
COMPOSE_ENV_FILE="${secrets_file}" \
RELEASE_ENV_FILE="${source_env_file}" \
RELEASE_ARTIFACT_ENV_FILE="${identity_file}" \
RELEASE_ARTIFACT_DIR="${artifact_directory}" \
DOCKER_PLATFORM="${platform}" \
    "${script_dir}/change-release.sh"

echo "==> [8/8] Running HTTP smoke checks against the Compose host binding"
COMPOSE_ENV_FILE="${secrets_file}" \
RELEASE_ENV_FILE="${source_env_file}" \
RELEASE_ARTIFACT_ENV_FILE="${identity_file}" \
    "${script_dir}/deployment-smoke.sh"

echo "Deployment start completed: ${release_version}."
