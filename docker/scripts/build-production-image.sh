#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
default_project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)
project_root=${RELEASE_PROJECT_ROOT:-${default_project_root}}

# shellcheck disable=SC1091
. "${default_project_root}/docker/release/release-lib.sh"

fail() {
    echo "Production image build error: $*" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "required command is missing: $1"
}

require_clean_repository() {
    repository=$1
    label=$2

    git -C "${repository}" rev-parse --is-inside-work-tree >/dev/null 2>&1 \
        || fail "${label} repository is unavailable: ${repository}"
    [ -z "$(git -C "${repository}" status --porcelain)" ] \
        || fail "${label} repository has uncommitted changes"
}

if [ "$(id -u)" -eq 0 ] || [ -n "${SUDO_USER:-}" ]; then
    fail "run as the build user, without sudo"
fi

for command_name in awk docker git grep mkdir php tr; do
    require_command "${command_name}"
done

image_prefix=${DOCKER_IMAGE_PREFIX:-}
release_version=${RELEASE_VERSION:-}
platform=${DOCKER_PLATFORM:-linux/amd64}
release_branch=${RELEASE_BRANCH:-main}
source_env_file=$(release_resolve_path "${RELEASE_SOURCE_ENV_FILE:-docker/compose/prod/release.env}")
compose_file=$(release_resolve_path "${RELEASE_COMPOSE_FILE:-docker/compose/prod/compose.yaml}")

case "${image_prefix}" in
    ''|*' '*|*@*|*/) fail "DOCKER_IMAGE_PREFIX must be a full registry namespace prefix without a tag or trailing slash" ;;
esac
printf '%s\n' "${release_version}" | grep -Eq '^v[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$' \
    || fail "RELEASE_VERSION must use vMAJOR.MINOR.PATCH syntax"
case "${platform}" in
    linux/amd64|linux/arm64) ;;
    *) fail "DOCKER_PLATFORM must be linux/amd64 or linux/arm64" ;;
esac

require_clean_repository "${project_root}" geo-registry
current_branch=$(git -C "${project_root}" branch --show-current)
[ "${current_branch}" = "${release_branch}" ] \
    || fail "release image must be built from ${release_branch}, current branch is ${current_branch:-detached}"
release_require_file "${source_env_file}"
release_require_file "${compose_file}"
source_identity_format=$(release_source_identity_format "${source_env_file}")
platform_slug=$(printf '%s' "${platform}" | tr / -)
image_tag="${release_version}-${platform_slug}"
image="${image_prefix}/app:${image_tag}"
revision=$(git -C "${project_root}" rev-parse HEAD)
release_validate_git_oid "${revision}"

case "${source_identity_format}" in
    1)
        if git -C "${project_root}" show-ref --verify --quiet "refs/tags/${release_version}"; then
            fail "release tag already exists and must not be reused: ${release_version}"
        fi
        config_revision=${revision}
        ;;
    2)
        release_validate_prepared_source_files "${source_env_file}" "${compose_file}"
        [ "$(release_env_value RELEASE_VERSION "${source_env_file}" || true)" = "${release_version}" ] \
            || fail "prepared source release version does not match RELEASE_VERSION"
        [ "$(release_env_value DOCKER_IMAGE_PREFIX "${source_env_file}" || true)" = "${image_prefix}" ] \
            || fail "prepared source image prefix does not match DOCKER_IMAGE_PREFIX"
        [ "$(release_env_value APP_IMAGE_TAG "${source_env_file}" || true)" = "${image_tag}" ] \
            || fail "prepared source image tag does not match release platform"
        [ "$(release_env_value RELEASE_PLATFORM "${source_env_file}" || true)" = "${platform}" ] \
            || fail "prepared source platform does not match DOCKER_PLATFORM"
        [ -z "$(release_env_value APP_IMAGE_DIGEST "${source_env_file}" || true)" ] \
            || fail "prepared source identity must not contain a post-build image digest"
        [ "$(awk '$1 == "x-app-image:" {value = $3; gsub(/^"|"$/, "", value); print value; exit}' "${compose_file}")" = "${image}" ] \
            || fail "prepared Compose image does not match release source identity"
        [ "$(awk '$1 == "APP_VERSION:" {value = $2; gsub(/^"|"$/, "", value); print value; exit}' "${compose_file}")" = "${release_version}" ] \
            || fail "prepared Compose version does not match release source identity"
        tag_commit=$(git -C "${project_root}" rev-parse "refs/tags/${release_version}^{commit}" 2>/dev/null || true)
        [ "${tag_commit}" = "${revision}" ] \
            || fail "prepared annotated tag ${release_version} must point to current HEAD before build"
        [ "$(git -C "${project_root}" cat-file -t "refs/tags/${release_version}" 2>/dev/null || true)" = tag ] \
            || fail "prepared release ref ${release_version} must be an annotated tag"
        config_revision=$(release_config_revision "${compose_file}" "${source_env_file}")
        ;;
esac

artifact_dir=$(release_resolve_path "${RELEASE_ARTIFACT_DIR:-docker/data/release/${release_version}}")
mkdir -p "${artifact_dir}"
release_acquire_output_lock \
    "${artifact_dir}/.release-${platform_slug}.lock" \
    build-production "${release_version}" "${platform}"

core_revision=$(release_locked_package_reference romanfedorskij/message-bus-async)
worker_revision=$(release_locked_package_reference romanfedorskij/message-bus-async-worker)

release_validate_git_oid "${core_revision}"
release_validate_git_oid "${worker_revision}"
phase_manifest_path="${artifact_dir}/phase-manifest.txt"

if [ -f "${phase_manifest_path}" ]; then
    phase_manifest_format=$(release_manifest_value phase_manifest_format "${phase_manifest_path}" || true)
    release_validate_phase_manifest_format "${phase_manifest_format}"
    if [ "${phase_manifest_format}" = 2 ]; then
        release_phase_manifest_expect "${phase_manifest_path}" release_version "${release_version}"
        release_phase_manifest_expect "${phase_manifest_path}" platform "${platform}"
        if [ "$(release_manifest_value built_state "${phase_manifest_path}" || true)" = verified ]; then
            release_phase_manifest_expect "${phase_manifest_path}" built_source_oid "${revision}"
            release_phase_manifest_expect "${phase_manifest_path}" built_config_revision "${config_revision}"
            release_phase_manifest_expect "${phase_manifest_path}" built_image_reference "${image}"
            release_phase_manifest_expect "${phase_manifest_path}" built_core_oid "${core_revision}"
            release_phase_manifest_expect "${phase_manifest_path}" built_worker_oid "${worker_revision}"

            exact_image=$(release_manifest_value built_image_digest "${phase_manifest_path}" || true)
            expected_image_id=$(release_manifest_value built_image_id "${phase_manifest_path}" || true)
            release_validate_image_id "${expected_image_id}"
            release_validate_registry_image "${image}" "${exact_image}" "${platform}" "${release_version}"
            actual_image_id=$(docker image inspect --format '{{.Id}}' "${exact_image}")
            [ "${actual_image_id}" = "${expected_image_id}" ] \
                || release_fail "verified build image ID is ${actual_image_id}, expected ${expected_image_id}; refusing an implicit rebuild"

            echo "Reusing verified production image: ${exact_image}"
            echo "Release metadata:"
            echo "DOCKER_IMAGE_PREFIX=${image_prefix}"
            echo "APP_IMAGE_TAG=${image_tag}"
            echo "APP_IMAGE_DIGEST=${exact_image#*@}"
            echo "RELEASE_VERSION=${release_version}"
            exit 0
        fi
    fi
fi

docker buildx build \
    --file "${project_root}/docker/php/production.Dockerfile" \
    --load \
    --platform "${platform}" \
    --build-arg "RELEASE_VERSION=${release_version}" \
    --build-arg "VCS_REF=${revision}" \
    --build-arg "MESSAGE_BUS_CORE_REF=${core_revision}" \
    --build-arg "MESSAGE_BUS_WORKER_REF=${worker_revision}" \
    --tag "${image}" \
    "${project_root}"

image_reference=$(docker image inspect --format '{{index .RepoDigests 0}}' "${image}")
case "${image_reference}" in
    "${image_prefix}/app@sha256:"*) image_digest=${image_reference#*@} ;;
    *) fail "built image does not expose the expected repository digest" ;;
esac
exact_image="${image_prefix}/app@${image_digest}"
image_id=$(docker image inspect --format '{{.Id}}' "${exact_image}")
release_validate_image_id "${image_id}"
release_validate_registry_image "${image}" "${exact_image}" "${platform}" "${release_version}"

phase_time=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
phase_actor=$(id -un)
release_phase_manifest_update "${phase_manifest_path}" \
    phase_manifest_format 2 \
    phase built \
    release_version "${release_version}" \
    platform "${platform}" \
    prepared_state verified \
    prepared_source_oid "${revision}" \
    prepared_config_revision "${config_revision}" \
    prepared_image_reference "${image}" \
    prepared_core_oid "${core_revision}" \
    prepared_worker_oid "${worker_revision}" \
    prepared_at "${phase_time}" \
    prepared_by "${phase_actor}" \
    built_state verified \
    built_source_oid "${revision}" \
    built_config_revision "${config_revision}" \
    built_image_reference "${image}" \
    built_image_digest "${exact_image}" \
    built_image_id "${image_id}" \
    built_core_oid "${core_revision}" \
    built_worker_oid "${worker_revision}" \
    built_at "${phase_time}" \
    built_by "${phase_actor}"

echo "Production image built: ${image}"
echo "Release metadata:"
echo "DOCKER_IMAGE_PREFIX=${image_prefix}"
echo "APP_IMAGE_TAG=${image_tag}"
echo "APP_IMAGE_DIGEST=${image_digest}"
echo "RELEASE_VERSION=${release_version}"
