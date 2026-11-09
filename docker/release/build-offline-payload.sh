#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
default_project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)
project_root=${RELEASE_PROJECT_ROOT:-${default_project_root}}

# shellcheck disable=SC1091
. "${script_dir}/release-lib.sh"

for command_name in awk docker git grep mkdir mktemp mv tr wc; do
    release_require_command "${command_name}"
done

if [ "$(id -u)" -eq 0 ] || [ -n "${SUDO_USER:-}" ]; then
    release_fail "run as the release user, without sudo"
fi

source_env_file=$(release_resolve_path "${RELEASE_ENV_FILE:-${RELEASE_SOURCE_ENV_FILE:-docker/compose/prod/release.env}}")
release_require_file "${source_env_file}"
compose_file=$(release_resolve_path "${RELEASE_COMPOSE_FILE:-docker/compose/prod/compose.yaml}")
release_require_file "${compose_file}"

release_version=$(release_env_value RELEASE_VERSION "${source_env_file}" || true)
platform=${DOCKER_PLATFORM:-linux/amd64}
release_branch=${RELEASE_BRANCH:-main}

release_validate_version "${release_version}"
release_validate_platform "${platform}"

platform_slug=$(printf '%s' "${platform}" | tr / -)
artifact_dir=$(release_resolve_path "${RELEASE_ARTIFACT_DIR:-docker/data/release/${release_version}}")
identity_file=$(release_identity_file "${source_env_file}" "${artifact_dir}")
release_require_file "${identity_file}"
image_tag=$(release_env_value APP_IMAGE_TAG "${identity_file}" || true)
image_digest=$(release_env_value APP_IMAGE_DIGEST "${identity_file}" || true)
image_prefix=$(release_env_value DOCKER_IMAGE_PREFIX "${identity_file}" || true)
release_validate_digest "${image_digest}"
release_validate_prefix "${image_prefix}"
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
release_validate_git_oid "${release_commit}"
release_validate_git_oid "${tag_commit}"
[ "${tag_commit}" = "${release_commit}" ] \
    || release_fail "annotated tag ${release_version} must point to the current commit"
[ "$(git -C "${project_root}" cat-file -t "refs/tags/${release_version}")" = tag ] \
    || release_fail "${release_version} must be an annotated tag"

application_source_commit=$(docker image inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "${exact_image}")
core_commit=$(docker image inspect --format '{{index .Config.Labels "org.geo-registry.message-bus-core.revision"}}' "${exact_image}")
worker_commit=$(docker image inspect --format '{{index .Config.Labels "org.geo-registry.message-bus-worker.revision"}}' "${exact_image}")
image_id=$(docker image inspect --format '{{.Id}}' "${exact_image}")
release_validate_git_oid "${application_source_commit}"
release_validate_git_oid "${core_commit}"
release_validate_git_oid "${worker_commit}"
release_validate_image_id "${image_id}"

git -C "${project_root}" cat-file -e "${application_source_commit}^{commit}" 2>/dev/null \
    || release_fail "image source revision is not available in the application repository"
source_identity_format=$(release_source_identity_format "${source_env_file}")
if [ "${source_identity_format}" = 2 ]; then
    release_validate_prepared_source_files "${source_env_file}" "${compose_file}"
    [ "${application_source_commit}" = "${release_commit}" ] \
        || release_fail "prepared release image source must equal the tagged release commit"
elif [ "${application_source_commit}" != "${release_commit}" ]; then
    release_changed_files=$(git -C "${project_root}" diff --name-only "${application_source_commit}" "${release_commit}")
    expected_release_files=$(printf '%s\n' docker/compose/prod/compose.yaml docker/compose/prod/release.env)
    case "${release_changed_files}" in
        docker/compose/prod/release.env|"${expected_release_files}") ;;
        *) release_fail "release commit differs from image source beyond the frozen release identity" ;;
    esac
fi
archive_name="source-${platform_slug}.tar"
archive_path="${artifact_dir}/${archive_name}"
manifest_path="${artifact_dir}/source-manifest.txt"
phase_manifest_path="${artifact_dir}/phase-manifest.txt"
mkdir -p "${artifact_dir}"
release_acquire_output_lock \
    "${artifact_dir}/.release-${platform_slug}.lock" \
    build-offline-payload "${release_version}" "${platform}"

config_revision=$(release_config_revision \
    "${compose_file}" "${source_env_file}")
if [ "${source_identity_format}" = 2 ]; then
    manifest_config_revision=${config_revision}
else
    manifest_config_revision=${release_commit}
fi
release_validate_artifact_identity \
    "${identity_file}" "${release_version}" "${platform}" \
    "${release_commit}" "${config_revision}" "${image_id}"
if [ -f "${phase_manifest_path}" ]; then
    phase_manifest_format=$(release_manifest_value phase_manifest_format "${phase_manifest_path}" || true)
    release_validate_phase_manifest_format "${phase_manifest_format}"
    if [ "${phase_manifest_format}" = 2 ]; then
        release_phase_manifest_expect "${phase_manifest_path}" release_version "${release_version}"
        release_phase_manifest_expect "${phase_manifest_path}" platform "${platform}"
        if [ "$(release_manifest_value built_state "${phase_manifest_path}" || true)" = verified ]; then
            release_phase_manifest_expect "${phase_manifest_path}" built_source_oid "${application_source_commit}"
            release_phase_manifest_expect "${phase_manifest_path}" built_image_reference "${tagged_image}"
            release_phase_manifest_expect "${phase_manifest_path}" built_image_digest "${exact_image}"
            release_phase_manifest_expect "${phase_manifest_path}" built_image_id "${image_id}"
        fi
        if [ "$(release_manifest_value frozen_state "${phase_manifest_path}" || true)" = verified ]; then
            release_phase_manifest_expect "${phase_manifest_path}" frozen_source_oid "${application_source_commit}"
            release_phase_manifest_expect "${phase_manifest_path}" frozen_config_revision "${config_revision}"
            release_phase_manifest_expect "${phase_manifest_path}" frozen_image_reference "${tagged_image}"
            release_phase_manifest_expect "${phase_manifest_path}" frozen_image_digest "${exact_image}"
        fi
        if [ "$(release_manifest_value offline_payload_state "${phase_manifest_path}" || true)" = verified ]; then
            release_phase_manifest_expect "${phase_manifest_path}" offline_payload_release_oid "${release_commit}"
            release_phase_manifest_expect "${phase_manifest_path}" offline_payload_config_revision "${config_revision}"
            release_phase_manifest_expect "${phase_manifest_path}" offline_payload_image_reference "${tagged_image}"
            release_phase_manifest_expect "${phase_manifest_path}" offline_payload_image_digest "${exact_image}"
            release_phase_manifest_expect "${phase_manifest_path}" offline_payload_image_id "${image_id}"
            release_phase_manifest_expect "${phase_manifest_path}" offline_payload_archive "${archive_name}"
            release_require_file "${archive_path}"
            release_require_file "${manifest_path}"
            expected_archive_size=$(release_manifest_value offline_payload_archive_size "${phase_manifest_path}" || true)
            expected_archive_sha256=$(release_manifest_value offline_payload_archive_sha256 "${phase_manifest_path}" || true)
            release_validate_size "${expected_archive_size}"
            [ "$(release_file_size "${archive_path}")" = "${expected_archive_size}" ] \
                || release_fail "verified offline payload size changed; refusing an implicit rebuild"
            [ "$(release_sha256 "${archive_path}")" = "${expected_archive_sha256}" ] \
                || release_fail "verified offline payload checksum changed; refusing an implicit rebuild"
            [ "$(release_manifest_value git_commit "${manifest_path}" || true)" = "${release_commit}" ] \
                || release_fail "verified offline payload manifest commit changed"
            [ "$(release_manifest_value image_1_id "${manifest_path}" || true)" = "${image_id}" ] \
                || release_fail "verified offline payload manifest image ID changed"

            echo "Reusing verified offline image payload: ${archive_path}"
            echo "Manifest: ${manifest_path}"
            echo "Archive SHA-256: ${expected_archive_sha256}"
            exit 0
        fi
    fi
fi

archive_temporary=$(mktemp "${artifact_dir}/.${archive_name}.tmp.XXXXXX")
manifest_temporary=$(mktemp "${artifact_dir}/.source-manifest.tmp.XXXXXX")
cleanup() {
    rm -f "${archive_temporary}" "${manifest_temporary}"
}
trap cleanup EXIT INT TERM

docker save --output "${archive_temporary}" "${tagged_image}"
archive_sha256=$(release_sha256 "${archive_temporary}")
archive_size=$(release_file_size "${archive_temporary}")
mv "${archive_temporary}" "${archive_path}"

{
    printf '%s\n' 'manifest_format=3'
    printf 'release_tag=%s\n' "${release_version}"
    printf 'release_version=%s\n' "${release_version}"
    printf 'git_commit=%s\n' "${release_commit}"
    printf 'config_revision=%s\n' "${manifest_config_revision}"
    printf 'application_source_commit=%s\n' "${application_source_commit}"
    printf 'message_bus_core_commit=%s\n' "${core_commit}"
    printf 'message_bus_worker_commit=%s\n' "${worker_commit}"
    printf 'platform=%s\n' "${platform}"
    printf 'platform_slug=%s\n' "${platform_slug}"
    printf 'archive=%s\n' "${archive_name}"
    printf 'archive_size=%s\n' "${archive_size}"
    printf 'archive_sha256=%s\n' "${archive_sha256}"
    printf '%s\n' 'image_count=1'
    printf 'image_1_reference=%s\n' "${tagged_image}"
    printf 'image_1_digest=%s\n' "${exact_image}"
    printf 'image_1_id=%s\n' "${image_id}"
    printf 'image_tag=%s\n' "${tagged_image}"
    printf 'image_digest=%s\n' "${exact_image}"
    printf '%s\n' 'assurance=integrity-only'
} > "${manifest_temporary}"

mv "${manifest_temporary}" "${manifest_path}"
trap - EXIT INT TERM

release_phase_manifest_update "${phase_manifest_path}" \
    phase_manifest_format 2 \
    phase offline-payload \
    release_version "${release_version}" \
    platform "${platform}" \
    release_oid "${release_commit}" \
    release_config_revision "${config_revision}" \
    application_source_oid "${application_source_commit}" \
    offline_payload_state verified \
    offline_payload_release_oid "${release_commit}" \
    offline_payload_config_revision "${config_revision}" \
    offline_payload_image_reference "${tagged_image}" \
    offline_payload_image_digest "${exact_image}" \
    offline_payload_image_id "${image_id}" \
    offline_payload_archive "${archive_name}" \
    offline_payload_archive_size "${archive_size}" \
    offline_payload_archive_sha256 "${archive_sha256}" \
    offline_payload_assurance integrity-only \
    offline_payload_verified_at "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" \
    offline_payload_verified_by "$(id -un)"

echo "Offline image payload created: ${archive_path}"
echo "Manifest: ${manifest_path}"
echo "Archive SHA-256: ${archive_sha256}"
