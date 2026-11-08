#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)

# shellcheck disable=SC1091
. "${script_dir}/release-lib.sh"

for command_name in awk docker git grep mkdir mktemp mv tr; do
    release_require_command "${command_name}"
done

if [ "$(id -u)" -eq 0 ] || [ -n "${SUDO_USER:-}" ]; then
    release_fail "run as the release user, without sudo"
fi

release_env_file=$(release_resolve_path "${RELEASE_ENV_FILE:-docker/compose/prod/release.env}")
release_require_file "${release_env_file}"

release_version=$(release_env_value RELEASE_VERSION "${release_env_file}" || true)
image_tag=$(release_env_value APP_IMAGE_TAG "${release_env_file}" || true)
image_digest=$(release_env_value APP_IMAGE_DIGEST "${release_env_file}" || true)
image_prefix=$(release_env_value DOCKER_IMAGE_PREFIX "${release_env_file}" || true)
platform=${DOCKER_PLATFORM:-linux/amd64}
release_branch=${RELEASE_BRANCH:-main}

release_validate_version "${release_version}"
release_validate_digest "${image_digest}"
release_validate_prefix "${image_prefix}"
release_validate_platform "${platform}"

platform_slug=$(printf '%s' "${platform}" | tr / -)
expected_tag="${release_version}-${platform_slug}"
[ "${image_tag}" = "${expected_tag}" ] \
    || release_fail "APP_IMAGE_TAG is ${image_tag}, expected ${expected_tag}"

[ -z "$(git -C "${project_root}" status --porcelain)" ] \
    || release_fail "repository must be clean before building release artifacts"
current_branch=$(git -C "${project_root}" branch --show-current)
[ "${current_branch}" = "${release_branch}" ] \
    || release_fail "release payload must be built from ${release_branch}, current branch is ${current_branch:-detached}"

tagged_image="${image_prefix}/app:${image_tag}"
exact_image="${image_prefix}/app@${image_digest}"
release_validate_registry_image "${tagged_image}" "${exact_image}" "${platform}" "${release_version}"

release_commit=$(git -C "${project_root}" rev-parse HEAD)
tag_commit=$(git -C "${project_root}" rev-parse "refs/tags/${release_version}^{commit}" 2>/dev/null || true)
[ "${tag_commit}" = "${release_commit}" ] \
    || release_fail "annotated tag ${release_version} must point to the current commit"
[ "$(git -C "${project_root}" cat-file -t "refs/tags/${release_version}")" = tag ] \
    || release_fail "${release_version} must be an annotated tag"

application_source_commit=$(docker image inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "${exact_image}")
core_commit=$(docker image inspect --format '{{index .Config.Labels "org.geo-registry.message-bus-core.revision"}}' "${exact_image}")
worker_commit=$(docker image inspect --format '{{index .Config.Labels "org.geo-registry.message-bus-worker.revision"}}' "${exact_image}")

git -C "${project_root}" cat-file -e "${application_source_commit}^{commit}" 2>/dev/null \
    || release_fail "image source revision is not available in the application repository"
if [ "${application_source_commit}" != "${release_commit}" ]; then
    release_changed_files=$(git -C "${project_root}" diff --name-only "${application_source_commit}" "${release_commit}")
    expected_release_files=$(printf '%s\n' docker/compose/prod/compose.yaml docker/compose/prod/release.env)
    case "${release_changed_files}" in
        docker/compose/prod/release.env|"${expected_release_files}") ;;
        *) release_fail "release commit differs from image source beyond the frozen release identity" ;;
    esac
fi

artifact_dir=$(release_resolve_path "${RELEASE_ARTIFACT_DIR:-docker/data/release/${release_version}}")
archive_name="source-${platform_slug}.tar"
archive_path="${artifact_dir}/${archive_name}"
manifest_path="${artifact_dir}/source-manifest.txt"
mkdir -p "${artifact_dir}"

archive_temporary=$(mktemp "${artifact_dir}/.${archive_name}.tmp.XXXXXX")
manifest_temporary=$(mktemp "${artifact_dir}/.source-manifest.tmp.XXXXXX")
cleanup() {
    rm -f "${archive_temporary}" "${manifest_temporary}"
}
trap cleanup EXIT INT TERM

docker save --output "${archive_temporary}" "${tagged_image}"
archive_sha256=$(release_sha256 "${archive_temporary}")
mv "${archive_temporary}" "${archive_path}"

{
    printf '%s\n' 'manifest_format=2'
    printf 'release_version=%s\n' "${release_version}"
    printf 'git_commit=%s\n' "${release_commit}"
    printf 'application_source_commit=%s\n' "${application_source_commit}"
    printf 'message_bus_core_commit=%s\n' "${core_commit}"
    printf 'message_bus_worker_commit=%s\n' "${worker_commit}"
    printf 'platform=%s\n' "${platform}"
    printf 'platform_slug=%s\n' "${platform_slug}"
    printf 'archive=%s\n' "${archive_name}"
    printf 'archive_sha256=%s\n' "${archive_sha256}"
    printf 'image_tag=%s\n' "${tagged_image}"
    printf 'image_digest=%s\n' "${exact_image}"
} > "${manifest_temporary}"

mv "${manifest_temporary}" "${manifest_path}"
trap - EXIT INT TERM

echo "Offline image payload created: ${archive_path}"
echo "Manifest: ${manifest_path}"
echo "Archive SHA-256: ${archive_sha256}"
