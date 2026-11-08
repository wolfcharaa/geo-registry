#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)

# shellcheck disable=SC1091
. "${script_dir}/release-lib.sh"

for command_name in awk docker git grep tr; do
    release_require_command "${command_name}"
done

if [ "$(id -u)" -eq 0 ] || [ -n "${SUDO_USER:-}" ]; then
    release_fail "run as the deployment user, without sudo"
fi

app_env_file=$(release_resolve_path "${COMPOSE_ENV_FILE:-config/prod.env}")
release_env_file=$(release_resolve_path "${RELEASE_ENV_FILE:-docker/compose/prod/release.env}")
release_require_file "${app_env_file}"
release_require_file "${release_env_file}"

release_version=$(release_env_value RELEASE_VERSION "${release_env_file}" || true)
image_tag=$(release_env_value APP_IMAGE_TAG "${release_env_file}" || true)
image_digest=$(release_env_value APP_IMAGE_DIGEST "${release_env_file}" || true)
image_prefix=$(release_env_value DOCKER_IMAGE_PREFIX "${app_env_file}" || true)
platform=${DOCKER_PLATFORM:-linux/amd64}

release_validate_version "${release_version}"
release_validate_digest "${image_digest}"
release_validate_prefix "${image_prefix}"
release_validate_platform "${platform}"

platform_slug=$(printf '%s' "${platform}" | tr / -)
expected_tag="${release_version}-${platform_slug}"
[ "${image_tag}" = "${expected_tag}" ] \
    || release_fail "APP_IMAGE_TAG is ${image_tag}, expected ${expected_tag}"

release_commit=$(git -C "${project_root}" rev-parse HEAD)
tag_commit=$(git -C "${project_root}" rev-parse "refs/tags/${release_version}^{commit}" 2>/dev/null || true)
[ "${tag_commit}" = "${release_commit}" ] \
    || release_fail "checkout must point to annotated tag ${release_version}"
[ "$(git -C "${project_root}" cat-file -t "refs/tags/${release_version}")" = tag ] \
    || release_fail "${release_version} must be an annotated tag"
[ -z "$(git -C "${project_root}" status --porcelain)" ] \
    || release_fail "release checkout must be clean before pulling its image"

tagged_image="${image_prefix}/app:${image_tag}"
exact_image="${image_prefix}/app@${image_digest}"

docker pull "${tagged_image}"
release_validate_image "${tagged_image}" "${exact_image}" "${platform}" "${release_version}"
application_source_commit=$(docker image inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "${exact_image}")
git -C "${project_root}" cat-file -e "${application_source_commit}^{commit}" 2>/dev/null \
    || release_fail "image source revision is not available in the application repository"
if [ "${application_source_commit}" != "${release_commit}" ]; then
    release_changed_files=$(git -C "${project_root}" diff --name-only "${application_source_commit}" "${release_commit}")
    [ "${release_changed_files}" = docker/compose/prod/release.env ] \
        || release_fail "release commit differs from image source beyond the frozen release identity"
fi

echo "Frozen release image pulled and verified: ${exact_image}"
