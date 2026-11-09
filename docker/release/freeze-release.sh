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

release_version=${RELEASE_VERSION:-${1:-}}
image_digest=${APP_IMAGE_DIGEST:-${2:-}}
platform=${DOCKER_PLATFORM:-linux/amd64}
release_branch=${RELEASE_BRANCH:-main}
release_env_file="${project_root}/docker/compose/prod/release.env"
compose_file="${project_root}/docker/compose/prod/compose.yaml"

release_validate_version "${release_version}"
release_validate_digest "${image_digest}"
release_validate_platform "${platform}"

[ -z "$(git -C "${project_root}" status --porcelain)" ] \
    || release_fail "repository must be clean before freezing a release"
current_branch=$(git -C "${project_root}" branch --show-current)
[ "${current_branch}" = "${release_branch}" ] \
    || release_fail "release identity must be frozen on ${release_branch}, current branch is ${current_branch:-detached}"
if git -C "${project_root}" show-ref --verify --quiet "refs/tags/${release_version}"; then
    release_fail "release tag already exists and must not be moved or reused: ${release_version}"
fi

image_prefix=${DOCKER_IMAGE_PREFIX:-}
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

if [ -f "${phase_manifest_path}" ]; then
    phase_manifest_format=$(release_manifest_value phase_manifest_format "${phase_manifest_path}" || true)
    release_validate_phase_manifest_format "${phase_manifest_format}"
    if [ "${phase_manifest_format}" = 2 ]; then
        release_phase_manifest_expect "${phase_manifest_path}" release_version "${release_version}"
        release_phase_manifest_expect "${phase_manifest_path}" platform "${platform}"
        release_phase_manifest_expect "${phase_manifest_path}" built_state verified
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

temporary_file=$(mktemp "${release_env_file}.tmp.XXXXXX")
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
mv "${temporary_file}" "${release_env_file}"
trap - EXIT INT TERM

config_revision=$(release_config_revision "${compose_file}" "${release_env_file}")
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
