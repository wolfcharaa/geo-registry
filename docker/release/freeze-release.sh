#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
default_project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)
project_root=${RELEASE_PROJECT_ROOT:-${default_project_root}}

# shellcheck disable=SC1091
. "${script_dir}/release-lib.sh"

for command_name in awk docker git grep mkdir mktemp mv tr; do
    release_require_command "${command_name}"
done

if [ "$(id -u)" -eq 0 ] || [ -n "${SUDO_USER:-}" ]; then
    release_fail "run as the release user, without sudo"
fi

release_version=${RELEASE_VERSION:-${1:-}}
image_digest=${APP_IMAGE_DIGEST:-${2:-}}
platform=${DOCKER_PLATFORM:-linux/amd64}
release_branch=${RELEASE_BRANCH:-main}
source_env_file=$(release_resolve_path "${RELEASE_SOURCE_ENV_FILE:-docker/compose/prod/release.env}")
compose_file=$(release_resolve_path "${RELEASE_COMPOSE_FILE:-docker/compose/prod/compose.yaml}")

release_validate_version "${release_version}"
release_validate_digest "${image_digest}"
release_validate_platform "${platform}"

[ -z "$(git -C "${project_root}" status --porcelain)" ] \
    || release_fail "repository must be clean before freezing a release"
current_branch=$(git -C "${project_root}" branch --show-current)
[ "${current_branch}" = "${release_branch}" ] \
    || release_fail "release identity must be frozen on ${release_branch}, current branch is ${current_branch:-detached}"
release_require_file "${source_env_file}"
release_require_file "${compose_file}"
source_identity_format=$(release_source_identity_format "${source_env_file}")

image_prefix=${DOCKER_IMAGE_PREFIX:-$(release_env_value DOCKER_IMAGE_PREFIX "${source_env_file}" || true)}
release_validate_prefix "${image_prefix}"
platform_slug=$(printf '%s' "${platform}" | tr / -)
artifact_dir=$(release_resolve_path "${RELEASE_ARTIFACT_DIR:-docker/data/release/${release_version}}")
mkdir -p "${artifact_dir}"
release_acquire_output_lock \
    "${artifact_dir}/.release-${platform_slug}.lock" \
    freeze-release "${release_version}" "${platform}"

image_tag="${release_version}-${platform_slug}"
tagged_image="${image_prefix}/app:${image_tag}"
exact_image="${image_prefix}/app@${image_digest}"
phase_manifest_path="${artifact_dir}/phase-manifest.txt"
config_revision=$(release_config_revision "${compose_file}" "${source_env_file}")

case "${source_identity_format}" in
    1)
        if git -C "${project_root}" show-ref --verify --quiet "refs/tags/${release_version}"; then
            release_fail "release tag already exists and must not be moved or reused: ${release_version}"
        fi
        ;;
    2)
        release_validate_prepared_source_files "${source_env_file}" "${compose_file}"
        [ "$(release_env_value RELEASE_VERSION "${source_env_file}" || true)" = "${release_version}" ] \
            || release_fail "prepared source release version does not match"
        [ "$(release_env_value APP_IMAGE_TAG "${source_env_file}" || true)" = "${image_tag}" ] \
            || release_fail "prepared source image tag does not match"
        [ "$(release_env_value RELEASE_PLATFORM "${source_env_file}" || true)" = "${platform}" ] \
            || release_fail "prepared source platform does not match"
        ;;
esac

if [ -f "${phase_manifest_path}" ]; then
    phase_manifest_format=$(release_manifest_value phase_manifest_format "${phase_manifest_path}" || true)
    release_validate_phase_manifest_format "${phase_manifest_format}"
    if [ "${phase_manifest_format}" = 2 ]; then
        release_phase_manifest_expect "${phase_manifest_path}" release_version "${release_version}"
        release_phase_manifest_expect "${phase_manifest_path}" platform "${platform}"
        release_phase_manifest_expect "${phase_manifest_path}" built_state verified
        if [ "${source_identity_format}" = 2 ]; then
            release_phase_manifest_expect "${phase_manifest_path}" built_config_revision "${config_revision}"
        fi
        release_phase_manifest_expect "${phase_manifest_path}" built_image_reference "${tagged_image}"
        release_phase_manifest_expect "${phase_manifest_path}" built_image_digest "${exact_image}"
        expected_image_id=$(release_manifest_value built_image_id "${phase_manifest_path}" || true)
        release_validate_image_id "${expected_image_id}"
        actual_image_id=$(docker image inspect --format '{{.Id}}' "${exact_image}")
        [ "${actual_image_id}" = "${expected_image_id}" ] \
            || release_fail "built image ID does not match verified phase state"
    fi
fi

release_validate_registry_image "${tagged_image}" "${exact_image}" "${platform}" "${release_version}"
current_commit=$(git -C "${project_root}" rev-parse HEAD)
image_source_commit=$(docker image inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "${exact_image}")
release_validate_git_oid "${current_commit}"
release_validate_git_oid "${image_source_commit}"
[ "${image_source_commit}" = "${current_commit}" ] \
    || release_fail "image source revision does not match current HEAD"

if [ "${source_identity_format}" = 2 ]; then
    tag_commit=$(git -C "${project_root}" rev-parse "refs/tags/${release_version}^{commit}" 2>/dev/null || true)
    [ "${tag_commit}" = "${current_commit}" ] \
        || release_fail "prepared annotated tag ${release_version} must point to current HEAD"
    [ "$(git -C "${project_root}" cat-file -t "refs/tags/${release_version}" 2>/dev/null || true)" = tag ] \
        || release_fail "prepared release ref ${release_version} must be an annotated tag"

    image_id=$(docker image inspect --format '{{.Id}}' "${exact_image}")
    release_validate_image_id "${image_id}"
    artifact_env_file=$(release_resolve_path \
        "${RELEASE_ARTIFACT_ENV_FILE:-${artifact_dir}/release-artifact.env}")
    if [ -f "${artifact_env_file}" ]; then
        release_validate_artifact_identity \
            "${artifact_env_file}" "${release_version}" "${platform}" \
            "${current_commit}" "${config_revision}" "${image_id}"
        [ "$(release_env_value DOCKER_IMAGE_PREFIX "${artifact_env_file}" || true)" = "${image_prefix}" ] \
            || release_fail "existing artifact image prefix does not match"
        [ "$(release_env_value APP_IMAGE_TAG "${artifact_env_file}" || true)" = "${image_tag}" ] \
            || release_fail "existing artifact image tag does not match"
        [ "$(release_env_value APP_IMAGE_DIGEST "${artifact_env_file}" || true)" = "${image_digest}" ] \
            || release_fail "existing artifact digest does not match; refusing to move release identity"
    else
        temporary_artifact=$(mktemp "${artifact_env_file}.tmp.XXXXXX")
        # shellcheck disable=SC2317
        cleanup_artifact() {
            rm -f "${temporary_artifact}"
        }
        trap cleanup_artifact EXIT INT TERM

        {
            printf '%s\n' '# Constructed release artifact identity. Generated after source preparation.'
            printf '%s\n' 'RELEASE_ARTIFACT_FORMAT=1'
            printf 'RELEASE_VERSION=%s\n' "${release_version}"
            printf 'RELEASE_SOURCE_OID=%s\n' "${current_commit}"
            printf 'RELEASE_CONFIG_REVISION=%s\n' "${config_revision}"
            printf 'DOCKER_PLATFORM=%s\n' "${platform}"
            printf 'DOCKER_IMAGE_PREFIX=%s\n' "${image_prefix}"
            printf 'APP_IMAGE_TAG=%s\n' "${image_tag}"
            printf 'APP_IMAGE_DIGEST=%s\n' "${image_digest}"
            printf 'APP_IMAGE_ID=%s\n' "${image_id}"
            printf '%s\n' 'COMPOSE_PROJECT_NAME=geo-registry'
        } >"${temporary_artifact}"
        mv "${temporary_artifact}" "${artifact_env_file}"
        trap - EXIT INT TERM
    fi

    phase_time=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
    release_phase_manifest_update "${phase_manifest_path}" \
        phase_manifest_format 2 \
        phase frozen \
        release_version "${release_version}" \
        platform "${platform}" \
        release_oid "${current_commit}" \
        release_config_revision "${config_revision}" \
        frozen_state verified \
        frozen_source_oid "${image_source_commit}" \
        frozen_base_oid "${current_commit}" \
        frozen_config_revision "${config_revision}" \
        frozen_image_reference "${tagged_image}" \
        frozen_image_digest "${exact_image}" \
        frozen_image_id "${image_id}" \
        frozen_artifact_env "${artifact_env_file}" \
        frozen_at "${phase_time}" \
        frozen_by "$(id -un)"

    echo "Production release artifact frozen: ${release_version}"
    echo "Image: ${exact_image}"
    echo "Artifact identity: ${artifact_env_file}"
    echo "Tracked source and Git refs were not changed."
    exit 0
fi

temporary_file=$(mktemp "${source_env_file}.tmp.XXXXXX")
temporary_compose=$(mktemp "${compose_file}.tmp.XXXXXX")
cleanup() {
    rm -f "${temporary_file}" "${temporary_compose}"
}
trap cleanup EXIT INT TERM

{
    printf '%s\n' '# Frozen release identity. Updated by docker/release/freeze-release.sh.'
    printf 'DOCKER_IMAGE_PREFIX=%s\n' "${image_prefix}"
    printf 'APP_IMAGE_TAG=%s\n' "${image_tag}"
    printf 'APP_IMAGE_DIGEST=%s\n' "${image_digest}"
    printf 'RELEASE_VERSION=%s\n' "${release_version}"
    printf '%s\n' 'COMPOSE_PROJECT_NAME=geo-registry'
} > "${temporary_file}"

awk -v image="${tagged_image}" -v version="${release_version}" '
    $1 == "x-app-image:" {
        print "x-app-image: &app-image \"" image "\""
        next
    }
    $1 == "APP_VERSION:" {
        print "  APP_VERSION: " version
        next
    }
    { print }
' "${compose_file}" >"${temporary_compose}"

docker compose \
    --env-file "${project_root}/config/prod.secrets.env.example" \
    --file "${temporary_compose}" config --quiet

mv "${temporary_compose}" "${compose_file}"
mv "${temporary_file}" "${source_env_file}"
trap - EXIT INT TERM

config_revision=$(release_config_revision "${compose_file}" "${source_env_file}")
phase_time=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
release_phase_manifest_update "${phase_manifest_path}" \
    phase_manifest_format 2 \
    phase frozen \
    release_version "${release_version}" \
    platform "${platform}" \
    frozen_state verified \
    frozen_source_oid "${image_source_commit}" \
    frozen_base_oid "${current_commit}" \
    frozen_config_revision "${config_revision}" \
    frozen_image_reference "${tagged_image}" \
    frozen_image_digest "${exact_image}" \
    frozen_at "${phase_time}" \
    frozen_by "$(id -un)"

echo "Production release identity frozen: ${release_version}"
echo "Image: ${exact_image}"
echo "Review and commit docker/compose/prod/compose.yaml with docker/compose/prod/release.env before tagging."
