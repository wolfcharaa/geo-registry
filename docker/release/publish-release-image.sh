#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
default_project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)
project_root=${RELEASE_PROJECT_ROOT:-${default_project_root}}

# shellcheck disable=SC1091
. "${script_dir}/release-lib.sh"

for command_name in awk date docker git grep id mkdir mktemp mv tr; do
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

artifact_dir=$(release_resolve_path "${RELEASE_ARTIFACT_DIR:-docker/data/release/${release_version}}")
identity_file=$(release_identity_file "${source_env_file}" "${artifact_dir}")
release_require_file "${identity_file}"
image_tag=$(release_env_value APP_IMAGE_TAG "${identity_file}" || true)
image_digest=$(release_env_value APP_IMAGE_DIGEST "${identity_file}" || true)
image_prefix=$(release_env_value DOCKER_IMAGE_PREFIX "${identity_file}" || true)
release_validate_digest "${image_digest}"
release_validate_prefix "${image_prefix}"

platform_slug=$(printf '%s' "${platform}" | tr / -)
expected_tag="${release_version}-${platform_slug}"
[ "${image_tag}" = "${expected_tag}" ] \
    || release_fail "APP_IMAGE_TAG is ${image_tag}, expected ${expected_tag}"

[ -z "$(git -C "${project_root}" status --porcelain)" ] \
    || release_fail "repository must be clean before publishing a release image"
current_branch=$(git -C "${project_root}" branch --show-current)
[ "${current_branch}" = "${release_branch}" ] \
    || release_fail "release image must be published from ${release_branch}, current branch is ${current_branch:-detached}"
release_commit=$(git -C "${project_root}" rev-parse HEAD)
tag_commit=$(git -C "${project_root}" rev-parse "refs/tags/${release_version}^{commit}" 2>/dev/null || true)
release_validate_git_oid "${release_commit}"
release_validate_git_oid "${tag_commit}"
[ "${tag_commit}" = "${release_commit}" ] \
    || release_fail "annotated tag ${release_version} must point to the current commit"
[ "$(git -C "${project_root}" cat-file -t "refs/tags/${release_version}")" = tag ] \
    || release_fail "${release_version} must be an annotated tag"

tagged_image="${image_prefix}/app:${image_tag}"
exact_image="${image_prefix}/app@${image_digest}"
mkdir -p "${artifact_dir}"
release_acquire_output_lock \
    "${artifact_dir}/.release-${platform_slug}.lock" \
    publish-release-image "${release_version}" "${platform}"

release_validate_registry_image "${tagged_image}" "${exact_image}" "${platform}" "${release_version}"
application_source_commit=$(docker image inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "${exact_image}")
image_id=$(docker image inspect --format '{{.Id}}' "${exact_image}")
release_validate_git_oid "${application_source_commit}"
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

if ! docker buildx imagetools inspect --help | grep -Fq -- '--format'; then
    release_fail "Docker Buildx imagetools inspect with --format is required before publication"
fi

phase_manifest_path="${artifact_dir}/phase-manifest.txt"
config_revision=$(release_config_revision \
    "${compose_file}" "${source_env_file}")
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
        if [ "$(release_manifest_value registry_state "${phase_manifest_path}" || true)" = verified ]; then
            release_phase_manifest_expect "${phase_manifest_path}" registry_release_oid "${release_commit}"
            release_phase_manifest_expect "${phase_manifest_path}" registry_config_revision "${config_revision}"
            release_phase_manifest_expect "${phase_manifest_path}" registry_image_reference "${tagged_image}"
            release_phase_manifest_expect "${phase_manifest_path}" registry_image_digest "${exact_image}"
            release_phase_manifest_expect "${phase_manifest_path}" registry_image_id "${image_id}"
            release_validate_remote_registry_image "${tagged_image}" "${exact_image}"
            echo "Reusing verified registry publication: ${exact_image}"
            echo "Publication evidence: ${phase_manifest_path}"
            exit 0
        fi
    fi
fi

docker push "${tagged_image}"
release_validate_remote_registry_image "${tagged_image}" "${exact_image}"

release_phase_manifest_update "${phase_manifest_path}" \
    phase_manifest_format 2 \
    phase registry-published \
    release_version "${release_version}" \
    platform "${platform}" \
    release_oid "${release_commit}" \
    release_config_revision "${config_revision}" \
    application_source_oid "${application_source_commit}" \
    registry_state verified \
    registry_release_oid "${release_commit}" \
    registry_config_revision "${config_revision}" \
    registry_image_reference "${tagged_image}" \
    registry_image_digest "${exact_image}" \
    registry_image_id "${image_id}" \
    registry_assurance registry-digest \
    registry_verified_at "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" \
    registry_verified_by "$(id -un)"

echo "Frozen release image published: ${exact_image}"
echo "Publication evidence: ${phase_manifest_path}"
