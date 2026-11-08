#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)

# shellcheck disable=SC1091
. "${script_dir}/release-lib.sh"

for command_name in awk docker git grep mktemp mv tr; do
    release_require_command "${command_name}"
done

if [ "$(id -u)" -eq 0 ] || [ -n "${SUDO_USER:-}" ]; then
    release_fail "run as the release user, without sudo"
fi

release_version=${RELEASE_VERSION:-${1:-}}
image_digest=${APP_IMAGE_DIGEST:-${2:-}}
platform=${DOCKER_PLATFORM:-linux/amd64}
release_branch=${RELEASE_BRANCH:-main}
app_env_file=$(release_resolve_path "${COMPOSE_ENV_FILE:-config/prod.env}")
release_env_file="${project_root}/docker/compose/prod/release.env"

release_validate_version "${release_version}"
release_validate_digest "${image_digest}"
release_validate_platform "${platform}"
release_require_file "${app_env_file}"

[ -z "$(git -C "${project_root}" status --porcelain)" ] \
    || release_fail "repository must be clean before freezing a release"
current_branch=$(git -C "${project_root}" branch --show-current)
[ "${current_branch}" = "${release_branch}" ] \
    || release_fail "release identity must be frozen on ${release_branch}, current branch is ${current_branch:-detached}"
if git -C "${project_root}" show-ref --verify --quiet "refs/tags/${release_version}"; then
    release_fail "release tag already exists and must not be moved or reused: ${release_version}"
fi

image_prefix=$(release_env_value DOCKER_IMAGE_PREFIX "${app_env_file}" || true)
release_validate_prefix "${image_prefix}"
platform_slug=$(printf '%s' "${platform}" | tr / -)
image_tag="${release_version}-${platform_slug}"
tagged_image="${image_prefix}/app:${image_tag}"
exact_image="${image_prefix}/app@${image_digest}"

release_validate_registry_image "${tagged_image}" "${exact_image}" "${platform}" "${release_version}"
current_commit=$(git -C "${project_root}" rev-parse HEAD)
image_source_commit=$(docker image inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "${exact_image}")
[ "${image_source_commit}" = "${current_commit}" ] \
    || release_fail "image source revision does not match current HEAD"

temporary_file=$(mktemp "${release_env_file}.tmp.XXXXXX")
cleanup() {
    rm -f "${temporary_file}"
}
trap cleanup EXIT INT TERM

{
    printf '%s\n' '# Frozen release identity. Updated by docker/release/freeze-release.sh.'
    printf 'APP_IMAGE_TAG=%s\n' "${image_tag}"
    printf 'APP_IMAGE_DIGEST=%s\n' "${image_digest}"
    printf 'RELEASE_VERSION=%s\n' "${release_version}"
    printf '%s\n' 'COMPOSE_PROJECT_NAME=geo-registry'
} > "${temporary_file}"

COMPOSE_ENV_FILE="${app_env_file}" \
    RELEASE_ENV_FILE="${temporary_file}" \
    "${project_root}/docker/compose/prod.sh" config --quiet

mv "${temporary_file}" "${release_env_file}"
trap - EXIT INT TERM

echo "Production release identity frozen: ${release_version}"
echo "Image: ${exact_image}"
echo "Review, commit and tag docker/compose/prod/release.env before building artifacts."
