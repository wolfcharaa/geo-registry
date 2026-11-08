#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
# Used by release_resolve_path from release-lib.sh.
# shellcheck disable=SC2034
project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)

# shellcheck disable=SC1091
. "${script_dir}/release-lib.sh"

for command_name in awk git grep tr; do
    release_require_command "${command_name}"
done

if [ "$(id -u)" -eq 0 ] || [ -n "${SUDO_USER:-}" ]; then
    release_fail "run as the deployment user, without sudo"
fi

release_env_file=$(release_resolve_path "${RELEASE_ENV_FILE:-docker/compose/prod/release.env}")
release_require_file "${release_env_file}"
release_version=$(release_env_value RELEASE_VERSION "${release_env_file}" || true)
release_validate_version "${release_version}"

platform=${DOCKER_PLATFORM:-linux/amd64}
release_validate_platform "${platform}"
platform_slug=$(printf '%s' "${platform}" | tr / -)
artifact_dir=$(release_resolve_path "${RELEASE_ARTIFACT_DIR:-docker/data/release/${release_version}}")
archive_path="${artifact_dir}/source-${platform_slug}.tar"
manifest_path="${artifact_dir}/source-manifest.txt"

if [ -f "${archive_path}" ] || [ -f "${manifest_path}" ]; then
    if [ ! -f "${archive_path}" ] || [ ! -f "${manifest_path}" ]; then
        release_fail "offline payload is incomplete: both ${archive_path} and ${manifest_path} are required"
    fi
    echo "Preparing the frozen application image from verified offline media."
else
    echo "Offline payload is absent; pulling the frozen application image from its registry."
    COMPOSE_ENV_FILE="${COMPOSE_ENV_FILE:-config/prod.secrets.env}" \
        RELEASE_ENV_FILE="${release_env_file}" \
        DOCKER_PLATFORM="${platform}" \
        "${script_dir}/pull-release-image.sh"
fi

COMPOSE_ENV_FILE="${COMPOSE_ENV_FILE:-config/prod.secrets.env}" \
    RELEASE_ENV_FILE="${release_env_file}" \
    RELEASE_ARTIFACT_DIR="${artifact_dir}" \
    RELEASE_DRY_RUN=1 \
    DOCKER_PLATFORM="${platform}" \
    "${script_dir}/change-release.sh"

echo "Deployment image is ready: ${release_version} (${platform})."
