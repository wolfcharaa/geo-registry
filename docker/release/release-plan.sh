#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
default_project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)
project_root=${RELEASE_PROJECT_ROOT:-${default_project_root}}

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
offline_scope=${RELEASE_OFFLINE_SCOPE:-app-update}
source_env_file=$(release_resolve_path "${RELEASE_SOURCE_ENV_FILE:-docker/compose/prod/release.env}")
compose_file=$(release_resolve_path "${RELEASE_COMPOSE_FILE:-docker/compose/prod/compose.yaml}")

release_validate_version "${release_version}"
release_validate_prefix "${image_prefix}"
release_validate_platform "${platform}"
release_validate_offline_scope "${offline_scope}"

platform_slug=$(printf '%s' "${platform}" | tr / -)
image_reference="${image_prefix}/app:${release_version}-${platform_slug}"
source_oid=$(git -C "${project_root}" rev-parse HEAD)
release_validate_git_oid "${source_oid}"
current_branch=$(git -C "${project_root}" branch --show-current)

source_state=clean
if [ -n "$(git -C "${project_root}" status --porcelain)" ]; then
    source_state=dirty
fi

source_identity_format=$(release_source_identity_format "${source_env_file}")
if [ "${source_identity_format}" = 2 ]; then
    release_validate_prepared_source_files "${source_env_file}" "${compose_file}"
fi
tag_state=absent
if git -C "${project_root}" show-ref --verify --quiet "refs/tags/${release_version}"; then
    tag_state=existing
    tag_commit=$(git -C "${project_root}" rev-parse "refs/tags/${release_version}^{commit}" 2>/dev/null || true)
    tag_type=$(git -C "${project_root}" cat-file -t "refs/tags/${release_version}" 2>/dev/null || true)
    if [ "${source_identity_format}" = 2 ] \
        && [ "${tag_commit}" = "${source_oid}" ] \
        && [ "${tag_type}" = tag ]; then
        tag_state=prepared
    fi
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
    "${compose_file}" \
    "${source_env_file}")

plan_status=ready
if [ "${source_state}" != clean ] \
    || [ "${current_branch}" != "${release_branch}" ] \
    || { [ "${source_identity_format}" = 1 ] && [ "${tag_state}" != absent ]; } \
    || { [ "${source_identity_format}" = 2 ] && [ "${tag_state}" != prepared ]; }; then
    plan_status=blocked
fi

if [ "${source_state}" != clean ]; then
    next_action=review-working-tree
elif [ "${current_branch}" != "${release_branch}" ]; then
    next_action=switch-release-branch
elif [ "${source_identity_format}" = 1 ]; then
    if [ "${tag_state}" = absent ]; then
        next_action=prepare-release-source
    else
        next_action=choose-new-release-version
    fi
elif [ "${tag_state}" = prepared ]; then
    next_action=build-prepared-source
elif [ "${tag_state}" = absent ]; then
    next_action=create-annotated-tag
else
    next_action=choose-new-release-version
fi

echo "Release plan (read-only)"
printf 'status=%s\n' "${plan_status}"
printf 'release_version=%s\n' "${release_version}"
printf 'release_branch=%s\n' "${release_branch}"
printf 'current_branch=%s\n' "${current_branch:-detached}"
printf 'source_state=%s\n' "${source_state}"
printf 'source_oid=%s\n' "${source_oid}"
printf 'source_identity_format=%s\n' "${source_identity_format}"
printf 'tag_state=%s\n' "${tag_state}"
printf 'platform=%s\n' "${platform}"
printf 'offline_scope=%s\n' "${offline_scope}"
printf 'image_reference=%s\n' "${image_reference}"
printf 'current_config_revision=%s\n' "${config_revision}"
printf 'message_bus_core_oid=%s\n' "${core_revision}"
printf 'message_bus_worker_oid=%s\n' "${worker_revision}"
printf 'buildx=%s\n' "${buildx_version}"
printf '%s\n' 'external_mutations=none'
printf 'next_action=%s\n' "${next_action}"
printf '%s\n' 'last_safe_abort=before-build'

[ "${plan_status}" = ready ] \
    || release_fail "release plan is blocked; no build, tag, push or artifact mutation was attempted"
