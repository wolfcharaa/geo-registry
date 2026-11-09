#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)

# shellcheck disable=SC1091
. "${project_root}/docker/release/release-lib.sh"
# shellcheck disable=SC1091
. "${project_root}/docker/release/deployment-lib.sh"

fail() {
    echo "Production Compose error: $*" >&2
    exit 1
}

resolve_path() {
    case "$1" in
        /*) printf '%s\n' "$1" ;;
        *) printf '%s\n' "${project_root}/$1" ;;
    esac
}

environment_value() {
    key=$1
    file=$2

    awk -F= -v key="${key}" '
        $1 == key {
            print substr($0, index($0, "=") + 1)
            found = 1
            exit
        }
        END {
            if (!found) {
                exit 1
            }
        }
    ' "${file}"
}

if [ "$(id -u)" -eq 0 ] || [ -n "${SUDO_USER:-}" ]; then
    fail "run as the deployment user, without sudo"
fi

[ "$#" -gt 0 ] || fail "provide a Docker Compose command"

for argument in "$@"; do
    case "${argument}" in
        --build) fail "production services must use a prebuilt image" ;;
        --pull|--pull=*) fail "production services must not override the frozen pull policy" ;;
        --watch) fail "production services must not use Compose watch" ;;
        --context|--context=*|-p|--project-name|--project-name=*|--project-directory|--project-directory=*|-f|--file|--file=*|--env-file|--env-file=*)
            fail "production target, project, Compose files and env files are owned by the wrapper"
            ;;
    esac
done

command_name=
expect_profile_value=0
for argument in "$@"; do
    if [ -n "${command_name}" ]; then
        continue
    fi
    if [ "${expect_profile_value}" = 1 ]; then
        deployment_validate_label "${argument}" \
            || fail "production Compose profile must be an explicit label"
        expect_profile_value=0
        continue
    fi
    case "${argument}" in
        --profile) expect_profile_value=1 ;;
        --profile=*)
            profile_name=${argument#--profile=}
            deployment_validate_label "${profile_name}" \
                || fail "production Compose profile must be an explicit label"
            ;;
        attach|build|commit|config|cp|create|down|events|exec|export|images|kill|logs|ls|pause|port|ps|publish|pull|push|restart|rm|run|scale|start|stats|stop|top|unpause|up|version|volumes|wait|watch)
            command_name=${argument}
            ;;
        -*) fail "unsupported production Compose global option: ${argument}" ;;
        *) fail "unexpected value before the Docker Compose command: ${argument}" ;;
    esac
done
[ "${expect_profile_value}" = 0 ] || fail "--profile requires a value"
[ -n "${command_name}" ] || fail "unsupported or missing Docker Compose command"

case "${command_name}" in
    build|publish|push|watch)
        fail "production build, publication and watch are not available through the deployment wrapper"
        ;;
    down|rm)
        for argument in "$@"; do
            case "${argument}" in
                -v|--volumes) fail "persistent volumes must not be removed by the application release workflow" ;;
            esac
        done
        ;;
esac

secrets_file=$(resolve_path "${COMPOSE_ENV_FILE:-config/prod.secrets.env}")
source_release_env_file=$(resolve_path "${RELEASE_ENV_FILE:-docker/compose/prod/release.env}")
release_env_file=${source_release_env_file}

[ -f "${secrets_file}" ] || fail "secrets file not found: ${secrets_file}"
[ -f "${release_env_file}" ] || fail "release environment file not found: ${release_env_file}"

image_prefix=$(environment_value DOCKER_IMAGE_PREFIX "${release_env_file}" || true)
image_tag=$(environment_value APP_IMAGE_TAG "${release_env_file}" || true)
image_digest=$(environment_value APP_IMAGE_DIGEST "${release_env_file}" || true)
release_version=$(environment_value RELEASE_VERSION "${release_env_file}" || true)

if [ -z "${image_digest}" ]; then
    artifact_env_file=$(resolve_path \
        "${RELEASE_ARTIFACT_ENV_FILE:-docker/data/release/${release_version}/release-artifact.env}")
    if [ -f "${artifact_env_file}" ]; then
        artifact_version=$(environment_value RELEASE_VERSION "${artifact_env_file}" || true)
        artifact_prefix=$(environment_value DOCKER_IMAGE_PREFIX "${artifact_env_file}" || true)
        artifact_tag=$(environment_value APP_IMAGE_TAG "${artifact_env_file}" || true)
        [ "${artifact_version}" = "${release_version}" ] \
            || fail "release artifact version does not match prepared source"
        [ "${artifact_prefix}" = "${image_prefix}" ] \
            || fail "release artifact image prefix does not match prepared source"
        [ "${artifact_tag}" = "${image_tag}" ] \
            || fail "release artifact image tag does not match prepared source"
        release_env_file=${artifact_env_file}
        image_digest=$(environment_value APP_IMAGE_DIGEST "${release_env_file}" || true)
    elif [ "${command_name}" != config ]; then
        fail "constructed artifact identity not found: ${artifact_env_file}"
    fi
fi

case "${image_prefix}" in
    ''|*' '*|*@*|*/) fail "DOCKER_IMAGE_PREFIX in release.env is invalid" ;;
esac
case "${image_tag}" in
    ''|*' '*|*/*|*@*|*:* ) fail "APP_IMAGE_TAG must be one frozen Docker tag" ;;
esac
if [ -n "${image_digest}" ]; then
    case "${image_digest}" in
        sha256:*) image_digest_hash=${image_digest#sha256:} ;;
        *) fail "APP_IMAGE_DIGEST must be a sha256 digest" ;;
    esac
    case "${image_digest_hash}" in
        *[!0-9a-f]*) fail "APP_IMAGE_DIGEST must contain lowercase hexadecimal characters" ;;
    esac
    [ "${#image_digest_hash}" -eq 64 ] \
        || fail "APP_IMAGE_DIGEST must contain 64 lowercase hexadecimal characters"
fi
case "${release_version}" in
    ''|*' '*|*/*|*@*|*:* ) fail "RELEASE_VERSION must be one release identifier" ;;
esac

compose_model=$(resolve_path "${PRODUCTION_COMPOSE_MODEL:-docker/compose/prod/compose.yaml}")
[ -f "${compose_model}" ] || fail "production Compose model not found: ${compose_model}"
source_identity_format=$(release_source_identity_format "${source_release_env_file}")
if [ "${source_identity_format}" = 2 ] && [ "${release_env_file}" != "${source_release_env_file}" ]; then
    current_source_oid=$(git -C "${project_root}" rev-parse HEAD)
    artifact_platform=$(environment_value DOCKER_PLATFORM "${release_env_file}" || true)
    source_config_revision=$(release_config_revision "${compose_model}" "${source_release_env_file}")
    release_validate_artifact_identity \
        "${release_env_file}" "${release_version}" "${artifact_platform}" \
        "${current_source_oid}" "${source_config_revision}"
fi
compose_image=$(awk '$1 == "x-app-image:" {value = $3; gsub(/^"|"$/, "", value); print value; exit}' "${compose_model}")
compose_version=$(awk '$1 == "APP_VERSION:" {value = $2; gsub(/^"|"$/, "", value); print value; exit}' "${compose_model}")
expected_image="${image_prefix}/app:${image_tag}"
[ "${compose_image}" = "${expected_image}" ] \
    || fail "production Compose image does not match frozen release.env"
[ "${compose_version}" = "${release_version}" ] \
    || fail "production Compose APP_VERSION does not match frozen release.env"

case "${command_name}" in
    config|events|images|logs|ls|port|ps|stats|top|version|volumes|wait)
        ;;
    *)
        deployment_require_confirmed_target \
            "compose:${command_name}" \
            "${DEPLOY_SERVICE_SCOPE:-compose-command}" \
            "${DEPLOY_DATA_MODE:-unspecified}" \
            "${release_env_file}"
        deployment_acquire_target_lock \
            "compose:${command_name}" \
            "${DEPLOY_SERVICE_SCOPE:-compose-command}" \
            "${DEPLOY_DATA_MODE:-unspecified}" \
            "${release_env_file}"
        ;;
esac

override_file="${project_root}/compose.override.yaml"
if [ -f "${override_file}" ]; then
    set -- docker compose \
        --env-file "${secrets_file}" \
        --env-file "${release_env_file}" \
        --file "${project_root}/compose.yaml" \
        --file "${override_file}" \
        "$@"
else
    set -- docker compose \
        --env-file "${secrets_file}" \
        --env-file "${release_env_file}" \
        --file "${project_root}/compose.yaml" \
        "$@"
fi

if [ "${command_name}" = up ]; then
    set -- "$@" --no-build --pull never
fi

cd "${project_root}"
exec "$@"
