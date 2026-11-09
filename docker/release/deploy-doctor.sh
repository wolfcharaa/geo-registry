#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
# Used by release_resolve_path from release-lib.sh.
# shellcheck disable=SC2034
project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)

# shellcheck disable=SC1091
. "${script_dir}/release-lib.sh"

for command_name in awk docker grep id sed; do
    release_require_command "${command_name}"
done

if [ "$(id -u)" -eq 0 ] || [ -n "${SUDO_USER:-}" ]; then
    release_fail "run as the deployment user, without sudo"
fi

output_prefix=${DEPLOY_DOCTOR_KEY_PREFIX:-}
blockers=
add_blocker() {
    if [ -z "${blockers}" ]; then
        blockers=$1
    else
        blockers="${blockers},$1"
    fi
}

field() {
    printf '%s%s=%s\n' "${output_prefix}" "$1" "$2"
}

validate_label() {
    printf '%s\n' "$1" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9._-]*$'
}

sanitize_endpoint() {
    endpoint=$1
    case "${endpoint}" in
        *://*@*)
            scheme=${endpoint%%://*}
            endpoint_host=${endpoint#*://}
            endpoint_host=${endpoint_host#*@}
            printf '%s://%s\n' "${scheme}" "${endpoint_host}"
            ;;
        *) printf '%s\n' "${endpoint}" ;;
    esac
}

target=${DEPLOY_TARGET:-}
if [ -z "${target}" ]; then
    target_state=missing
    target='unset'
    add_blocker target-missing
elif ! validate_label "${target}"; then
    target_state=invalid
    add_blocker target-invalid
else
    target_state=explicit
fi

expected_context=${DEPLOY_EXPECTED_DOCKER_CONTEXT:-}
if [ -z "${expected_context}" ]; then
    expected_context_state=missing
    expected_context='unset'
    add_blocker expected-context-missing
elif ! validate_label "${expected_context}"; then
    expected_context_state=invalid
    add_blocker expected-context-invalid
else
    expected_context_state=explicit
fi

current_context=$(docker context show 2>/dev/null || true)
if [ -z "${current_context}" ]; then
    current_context=unavailable
    context_state=unavailable
    add_blocker docker-context-unavailable
elif [ "${expected_context_state}" = explicit ] \
    && [ "${current_context}" != "${expected_context}" ]; then
    context_state=mismatch
    add_blocker docker-context-mismatch
else
    context_state=matched
fi

context_endpoint=unavailable
if [ "${current_context}" != unavailable ]; then
    raw_context_endpoint=$(docker context inspect "${current_context}" \
        --format '{{(index .Endpoints "docker").Host}}' 2>/dev/null || true)
    if [ -n "${raw_context_endpoint}" ]; then
        context_endpoint=$(sanitize_endpoint "${raw_context_endpoint}")
    else
        add_blocker docker-context-endpoint-unavailable
    fi
fi

docker_client=$(docker --version 2>/dev/null | sed 's/,.*//' || true)
if [ -z "${docker_client}" ]; then
    docker_client=unavailable
    add_blocker docker-client-unavailable
fi

compose_version=$(docker compose version --short 2>/dev/null || true)
if [ -z "${compose_version}" ]; then
    compose_version=unavailable
    add_blocker compose-unavailable
fi

compose_hash_capability=unavailable
compose_config_help=$(docker compose config --help 2>/dev/null || true)
if printf '%s\n' "${compose_config_help}" | grep -Fq -- '--hash'; then
    compose_hash_capability=available
else
    add_blocker compose-hash-unavailable
fi

engine_state=unavailable
host_platform=unavailable
docker_server=unavailable
engine_info=
if engine_info=$(docker info \
    --format '{{.OSType}}/{{.Architecture}}|{{.ServerVersion}}' 2>/dev/null); then
    discovered_host_platform=${engine_info%%|*}
    discovered_docker_server=${engine_info#*|}
    case "${discovered_host_platform}" in
        linux/amd64|linux/arm64)
            if [ -n "${discovered_docker_server}" ]; then
                engine_state=available
                host_platform=${discovered_host_platform}
                docker_server=${discovered_docker_server}
            else
                add_blocker docker-engine-identity-invalid
            fi
            ;;
        *) add_blocker docker-host-platform-unsupported ;;
    esac
else
    add_blocker docker-engine-unavailable
fi

secrets_file=$(release_resolve_path "${COMPOSE_ENV_FILE:-config/prod.secrets.env}")
if [ -f "${secrets_file}" ] && [ -r "${secrets_file}" ]; then
    secrets_state=present-readable
else
    secrets_state=missing-or-unreadable
    add_blocker production-secrets-unavailable
fi

release_env_file=$(release_resolve_path \
    "${RELEASE_ENV_FILE:-${RELEASE_SOURCE_ENV_FILE:-docker/compose/prod/release.env}}")
compose_model=$(release_resolve_path \
    "${PRODUCTION_COMPOSE_MODEL:-docker/compose/prod/compose.yaml}")
if [ -f "${release_env_file}" ]; then
    release_identity_state=present
    compose_project=$(release_env_value COMPOSE_PROJECT_NAME "${release_env_file}" || true)
    if [ -z "${compose_project}" ]; then
        compose_project=unavailable
        add_blocker compose-project-missing
    elif ! validate_label "${compose_project}"; then
        add_blocker compose-project-invalid
    fi
else
    release_identity_state=missing
    compose_project=unavailable
    add_blocker release-identity-missing
fi
if [ ! -f "${compose_model}" ]; then
    add_blocker production-compose-model-missing
fi

doctor_status=ready
if [ -n "${blockers}" ]; then
    doctor_status=blocked
else
    blockers=none
fi

if [ -z "${DEPLOY_DOCTOR_EMBEDDED:-}" ]; then
    echo "Deployment doctor (read-only)"
fi
field status "${doctor_status}"
field target "${target}"
field target_state "${target_state}"
field expected_docker_context "${expected_context}"
field docker_context "${current_context}"
field docker_context_state "${context_state}"
field docker_context_endpoint "${context_endpoint}"
field compose_project "${compose_project}"
field host_platform "${host_platform}"
field docker_client "${docker_client}"
field docker_server "${docker_server}"
field compose_version "${compose_version}"
field compose_hash_capability "${compose_hash_capability}"
field engine_state "${engine_state}"
field secrets_state "${secrets_state}"
field release_identity_state "${release_identity_state}"
field blockers "${blockers}"
field external_mutations none
field last_safe_abort before-artifact-acquire

if [ "${doctor_status}" != ready ]; then
    echo "Deployment doctor is blocked; no target state was changed." >&2
    exit 1
fi
