#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)

# shellcheck disable=SC1091
. "${script_dir}/release-lib.sh"

for command_name in awk docker git grep id php tr; do
    release_require_command "${command_name}"
done

if [ "$(id -u)" -eq 0 ] || [ -n "${SUDO_USER:-}" ]; then
    release_fail "run as the release user, without sudo"
fi

release_version=${RELEASE_VERSION:-${1:-}}
image_prefix=${DOCKER_IMAGE_PREFIX:-}
platform=${DOCKER_PLATFORM:-linux/amd64}
release_branch=${RELEASE_BRANCH:-main}

release_validate_version "${release_version}"
release_validate_prefix "${image_prefix}"
release_validate_platform "${platform}"

platform_slug=$(printf '%s' "${platform}" | tr / -)
image_reference="${image_prefix}/app:${release_version}-${platform_slug}"
source_oid=$(git -C "${project_root}" rev-parse HEAD)
release_validate_git_oid "${source_oid}"
current_branch=$(git -C "${project_root}" branch --show-current)

source_state=clean
if [ -n "$(git -C "${project_root}" status --porcelain)" ]; then
    source_state=dirty
fi

tag_state=absent
if git -C "${project_root}" show-ref --verify --quiet "refs/tags/${release_version}"; then
    tag_state=existing
fi

buildx_version=$(docker buildx version 2>/dev/null) \
    || release_fail "Docker Buildx is required for production artifacts"
buildx_help=$(docker buildx build --help 2>/dev/null) \
    || release_fail "Docker Buildx build capability is unavailable"
printf '%s\n' "${buildx_help}" | grep -Fq -- '--platform' \
    || release_fail "Docker Buildx build must support --platform"
printf '%s\n' "${buildx_help}" | grep -Fq -- '--load' \
    || release_fail "Docker Buildx build must support --load"

core_revision=$(release_locked_package_reference romanfedorskij/message-bus-async)
worker_revision=$(release_locked_package_reference romanfedorskij/message-bus-async-worker)
release_validate_git_oid "${core_revision}"
release_validate_git_oid "${worker_revision}"
config_revision=$(release_config_revision \
    "${project_root}/docker/compose/prod/compose.yaml" \
    "${project_root}/docker/compose/prod/release.env")

plan_status=ready
if [ "${source_state}" != clean ] \
    || [ "${current_branch}" != "${release_branch}" ] \
    || [ "${tag_state}" != absent ]; then
    plan_status=blocked
fi

echo "Release plan (read-only)"
printf 'status=%s\n' "${plan_status}"
printf 'release_version=%s\n' "${release_version}"
printf 'release_branch=%s\n' "${release_branch}"
printf 'current_branch=%s\n' "${current_branch:-detached}"
printf 'source_state=%s\n' "${source_state}"
printf 'source_oid=%s\n' "${source_oid}"
printf 'tag_state=%s\n' "${tag_state}"
printf 'platform=%s\n' "${platform}"
printf 'image_reference=%s\n' "${image_reference}"
printf 'current_config_revision=%s\n' "${config_revision}"
printf 'message_bus_core_oid=%s\n' "${core_revision}"
printf 'message_bus_worker_oid=%s\n' "${worker_revision}"
printf 'buildx=%s\n' "${buildx_version}"
printf '%s\n' 'external_mutations=none'
printf '%s\n' 'next_action=review-before-build'
printf '%s\n' 'last_safe_abort=before-build'

[ "${plan_status}" = ready ] \
    || release_fail "release plan is blocked; no build, tag, push or artifact mutation was attempted"
