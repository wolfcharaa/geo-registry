#!/bin/sh

deployment_validate_label() {
    deployment_label=$1

    case "${deployment_label}" in
        ''|*[!A-Za-z0-9._-]*) return 1 ;;
    esac
    case "${deployment_label}" in
        [A-Za-z0-9]*) return 0 ;;
        *) return 1 ;;
    esac
}

deployment_sanitize_endpoint() {
    deployment_endpoint=$1

    case "${deployment_endpoint}" in
        *://*@*)
            deployment_scheme=${deployment_endpoint%%://*}
            deployment_endpoint_host=${deployment_endpoint#*://}
            deployment_endpoint_host=${deployment_endpoint_host#*@}
            printf '%s://%s\n' "${deployment_scheme}" "${deployment_endpoint_host}"
            ;;
        *) printf '%s\n' "${deployment_endpoint}" ;;
    esac
}

deployment_require_confirmed_target() {
    deployment_operation=$1
    deployment_scope=$2
    deployment_data_mode=$3
    deployment_identity_file=$4

    release_require_command docker
    release_require_file "${deployment_identity_file}"

    deployment_target=${DEPLOY_TARGET:-}
    deployment_validate_label "${deployment_target}" \
        || release_fail "DEPLOY_TARGET must be an explicit label beginning with an alphanumeric character"

    deployment_expected_context=${DEPLOY_EXPECTED_DOCKER_CONTEXT:-}
    deployment_validate_label "${deployment_expected_context}" \
        || release_fail "DEPLOY_EXPECTED_DOCKER_CONTEXT must name the explicitly selected Docker context"

    deployment_confirmed_target=${DEPLOY_CONFIRM_TARGET:-}
    [ "${deployment_confirmed_target}" = "${deployment_target}" ] \
        || release_fail "DEPLOY_CONFIRM_TARGET must exactly repeat DEPLOY_TARGET before mutation"

    deployment_current_context=$(docker context show 2>/dev/null || true)
    [ -n "${deployment_current_context}" ] \
        || release_fail "cannot resolve the active Docker context"
    [ "${deployment_current_context}" = "${deployment_expected_context}" ] \
        || release_fail "active Docker context is ${deployment_current_context}, expected ${deployment_expected_context}"

    deployment_raw_endpoint=$(docker context inspect "${deployment_current_context}" \
        --format '{{(index .Endpoints "docker").Host}}' 2>/dev/null || true)
    [ -n "${deployment_raw_endpoint}" ] \
        || release_fail "cannot resolve the active Docker context endpoint"
    deployment_endpoint=$(deployment_sanitize_endpoint "${deployment_raw_endpoint}")

    deployment_project=$(release_env_value COMPOSE_PROJECT_NAME \
        "${deployment_identity_file}" || true)
    deployment_validate_label "${deployment_project}" \
        || release_fail "COMPOSE_PROJECT_NAME must be explicit in the release identity"

    DEPLOY_SERVICE_SCOPE=${deployment_scope}
    DEPLOY_DATA_MODE=${deployment_data_mode}
    export DEPLOY_SERVICE_SCOPE DEPLOY_DATA_MODE

    if [ "${DEPLOY_TARGET_GATE_QUIET:-0}" != 1 ]; then
        echo "Deployment target confirmed"
        printf 'operation=%s\n' "${deployment_operation}"
        printf 'target=%s\n' "${deployment_target}"
        printf 'docker_context=%s\n' "${deployment_current_context}"
        printf 'docker_context_endpoint=%s\n' "${deployment_endpoint}"
        printf 'compose_project=%s\n' "${deployment_project}"
        printf 'service_scope=%s\n' "${deployment_scope}"
        printf 'data_mode=%s\n' "${deployment_data_mode}"
    fi
}

deployment_acquire_target_lock() {
    deployment_lock_operation=$1
    deployment_lock_scope=$2
    deployment_lock_data_mode=$3
    deployment_lock_identity_file=$4

    for deployment_lock_command in date flock id mkdir readlink sed; do
        release_require_command "${deployment_lock_command}"
    done

    deployment_lock_target=${DEPLOY_TARGET:-}
    deployment_validate_label "${deployment_lock_target}" \
        || release_fail "cannot lock an invalid deployment target"
    deployment_lock_context=${DEPLOY_EXPECTED_DOCKER_CONTEXT:-}
    deployment_validate_label "${deployment_lock_context}" \
        || release_fail "cannot lock an invalid Docker context"
    deployment_lock_project=$(release_env_value COMPOSE_PROJECT_NAME \
        "${deployment_lock_identity_file}" || true)
    deployment_validate_label "${deployment_lock_project}" \
        || release_fail "cannot lock an invalid Compose project"

    deployment_state_root=$(release_resolve_path \
        "${DEPLOY_STATE_ROOT:-docker/data/deployment}")
    deployment_target_state_directory="${deployment_state_root}/${deployment_lock_target}"
    umask 077
    mkdir -p "${deployment_target_state_directory}"
    [ ! -L "${deployment_target_state_directory}" ] \
        || release_fail "deployment target state directory must not be a symbolic link"
    deployment_expected_lock_file="${deployment_target_state_directory}/deployment.lock"
    [ ! -L "${deployment_expected_lock_file}" ] \
        || release_fail "deployment target lock must not be a symbolic link"

    if [ -n "${DEPLOY_LOCK_FILE:-}" ]; then
        [ "${DEPLOY_LOCK_FILE}" = "${deployment_expected_lock_file}" ] \
            || release_fail "inherited deployment lock belongs to another path"
        [ "${DEPLOY_LOCK_TARGET:-}" = "${deployment_lock_target}" ] \
            || release_fail "inherited deployment lock belongs to another target"
        [ -e "/proc/$$/fd/8" ] \
            || release_fail "inherited deployment lock descriptor is unavailable"
        deployment_inherited_lock_file=$(readlink -f "/proc/$$/fd/8" 2>/dev/null || true)
        [ "${deployment_inherited_lock_file}" = "${deployment_expected_lock_file}" ] \
            || release_fail "inherited deployment lock descriptor points to another file"
        flock -n 8 \
            || release_fail "inherited deployment lock is not owned by this operation"
        return
    fi

    exec 8>>"${deployment_expected_lock_file}"
    if ! flock -n 8; then
        echo "Active deployment target lock: ${deployment_expected_lock_file}" >&2
        sed -n '1,20p' "${deployment_expected_lock_file}" >&2 || true
        release_fail "another deployment operation owns target ${deployment_lock_target}"
    fi

    : >"${deployment_expected_lock_file}"
    {
        printf '%s\n' 'lock_format=1'
        printf '%s\n' 'state=active'
        printf 'operation=%s\n' "${deployment_lock_operation}"
        printf 'target=%s\n' "${deployment_lock_target}"
        printf 'docker_context=%s\n' "${deployment_lock_context}"
        printf 'compose_project=%s\n' "${deployment_lock_project}"
        printf 'service_scope=%s\n' "${deployment_lock_scope}"
        printf 'data_mode=%s\n' "${deployment_lock_data_mode}"
        printf 'pid=%s\n' "$$"
        printf 'actor=%s\n' "$(id -un)"
        printf 'started_at=%s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
    } >&8

    DEPLOY_LOCK_FILE=${deployment_expected_lock_file}
    DEPLOY_LOCK_TARGET=${deployment_lock_target}
    export DEPLOY_LOCK_FILE DEPLOY_LOCK_TARGET
    echo "Deployment target lock acquired: ${deployment_expected_lock_file}"
}
