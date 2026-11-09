#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
default_project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)
project_root=${RELEASE_PROJECT_ROOT:-${default_project_root}}

# shellcheck disable=SC1091
. "${script_dir}/release-lib.sh"

for command_name in awk docker git grep id mktemp mv rm tr; do
    release_require_command "${command_name}"
done

if [ "$(id -u)" -eq 0 ] || [ -n "${SUDO_USER:-}" ]; then
    release_fail "run as the release user, without sudo"
fi

release_version=${RELEASE_VERSION:-${1:-}}
image_prefix=${DOCKER_IMAGE_PREFIX:-}
platform=${DOCKER_PLATFORM:-linux/amd64}
release_branch=${RELEASE_BRANCH:-main}
compose_file=$(release_resolve_path "${RELEASE_COMPOSE_FILE:-docker/compose/prod/compose.yaml}")
source_env_file=$(release_resolve_path "${RELEASE_SOURCE_ENV_FILE:-docker/compose/prod/release.env}")
secrets_example=$(release_resolve_path "${RELEASE_SECRETS_EXAMPLE_FILE:-config/prod.secrets.env.example}")

release_validate_version "${release_version}"
release_validate_prefix "${image_prefix}"
release_validate_platform "${platform}"
release_require_file "${compose_file}"
release_require_file "${source_env_file}"
release_require_file "${secrets_example}"
release_validate_prepared_source_files "${source_env_file}" "${compose_file}"

[ -z "$(git -C "${project_root}" status --porcelain)" ] \
    || release_fail "repository must be clean before preparing release source"
current_branch=$(git -C "${project_root}" branch --show-current)
[ "${current_branch}" = "${release_branch}" ] \
    || release_fail "release source must be prepared on ${release_branch}, current branch is ${current_branch:-detached}"
if git -C "${project_root}" show-ref --verify --quiet "refs/tags/${release_version}"; then
    release_fail "release tag already exists and must not be moved or reused: ${release_version}"
fi

platform_slug=$(printf '%s' "${platform}" | tr / -)
image_tag="${release_version}-${platform_slug}"
tagged_image="${image_prefix}/app:${image_tag}"
user_agent_version=$(printf '%s\n' "${release_version#v}" | awk -F. '{ print $1 "." $2 }')
user_agent="geo-registry/${user_agent_version}"
temporary_compose=$(mktemp "${compose_file}.tmp.XXXXXX")
temporary_env=$(mktemp "${source_env_file}.tmp.XXXXXX")
cleanup() {
    rm -f "${temporary_compose}" "${temporary_env}"
}
trap cleanup EXIT INT TERM

awk -v image="${tagged_image}" -v version="${release_version}" -v user_agent="${user_agent}" '
    $1 == "x-app-image:" {
        print "x-app-image: &app-image \"" image "\""
        next
    }
    $1 == "APP_VERSION:" {
        print "  APP_VERSION: " version
        next
    }
    $1 == "APP_USER_AGENT:" {
        print "  APP_USER_AGENT: " user_agent
        next
    }
    { print }
' "${compose_file}" >"${temporary_compose}"

{
    printf '%s\n' '# Prepared release source identity. Exact artifact identity is generated after build.'
    printf '%s\n' 'RELEASE_IDENTITY_FORMAT=2'
    printf 'DOCKER_IMAGE_PREFIX=%s\n' "${image_prefix}"
    printf 'APP_IMAGE_TAG=%s\n' "${image_tag}"
    printf 'RELEASE_VERSION=%s\n' "${release_version}"
    printf 'RELEASE_PLATFORM=%s\n' "${platform}"
    printf '%s\n' 'COMPOSE_PROJECT_NAME=geo-registry'
} >"${temporary_env}"

docker compose \
    --env-file "${secrets_example}" \
    --env-file "${temporary_env}" \
    --file "${temporary_compose}" config --quiet

mv "${temporary_compose}" "${compose_file}"
mv "${temporary_env}" "${source_env_file}"
trap - EXIT INT TERM

echo "Release source prepared: ${release_version}"
echo "Image reference: ${tagged_image}"
echo "Review and commit ${compose_file#"${project_root}/"} with ${source_env_file#"${project_root}/"}."
echo "Create one annotated ${release_version} tag on that commit before artifact construction."
echo "No image, artifact, commit or tag was created."
