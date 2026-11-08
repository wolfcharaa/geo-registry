#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)

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
    esac
done

command_name=$1
case "${command_name}" in
    down|rm)
        for argument in "$@"; do
            case "${argument}" in
                -v|--volumes) fail "persistent volumes must not be removed by the application release workflow" ;;
            esac
        done
        ;;
esac

secrets_file=$(resolve_path "${COMPOSE_ENV_FILE:-config/prod.secrets.env}")
release_env_file=$(resolve_path "${RELEASE_ENV_FILE:-docker/compose/prod/release.env}")

[ -f "${secrets_file}" ] || fail "secrets file not found: ${secrets_file}"
[ -f "${release_env_file}" ] || fail "release environment file not found: ${release_env_file}"

image_prefix=$(environment_value DOCKER_IMAGE_PREFIX "${release_env_file}" || true)
image_tag=$(environment_value APP_IMAGE_TAG "${release_env_file}" || true)
image_digest=$(environment_value APP_IMAGE_DIGEST "${release_env_file}" || true)
release_version=$(environment_value RELEASE_VERSION "${release_env_file}" || true)

case "${image_prefix}" in
    ''|*' '*|*@*|*/) fail "DOCKER_IMAGE_PREFIX in release.env is invalid" ;;
esac
case "${image_tag}" in
    ''|*' '*|*/*|*@*|*:* ) fail "APP_IMAGE_TAG must be one frozen Docker tag" ;;
esac
case "${image_digest}" in
    sha256:*) image_digest_hash=${image_digest#sha256:} ;;
    *) fail "APP_IMAGE_DIGEST must be a sha256 digest" ;;
esac
case "${image_digest_hash}" in
    *[!0-9a-f]*) fail "APP_IMAGE_DIGEST must contain lowercase hexadecimal characters" ;;
esac
[ "${#image_digest_hash}" -eq 64 ] \
    || fail "APP_IMAGE_DIGEST must contain 64 lowercase hexadecimal characters"
case "${release_version}" in
    ''|*' '*|*/*|*@*|*:* ) fail "RELEASE_VERSION must be one release identifier" ;;
esac

compose_model=$(resolve_path "${PRODUCTION_COMPOSE_MODEL:-docker/compose/prod/compose.yaml}")
[ -f "${compose_model}" ] || fail "production Compose model not found: ${compose_model}"
compose_image=$(awk '$1 == "x-app-image:" {value = $3; gsub(/^"|"$/, "", value); print value; exit}' "${compose_model}")
compose_version=$(awk '$1 == "APP_VERSION:" {value = $2; gsub(/^"|"$/, "", value); print value; exit}' "${compose_model}")
expected_image="${image_prefix}/app:${image_tag}"
[ "${compose_image}" = "${expected_image}" ] \
    || fail "production Compose image does not match frozen release.env"
[ "${compose_version}" = "${release_version}" ] \
    || fail "production Compose APP_VERSION does not match frozen release.env"

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
