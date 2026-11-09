#!/bin/sh

deployment_evidence_update() (
    set -eu

    [ -n "${DEPLOY_EVIDENCE_FILE:-}" ] \
        || release_fail "deployment evidence is not active"
    [ -f "${DEPLOY_EVIDENCE_FILE}" ] \
        || release_fail "deployment evidence file is missing: ${DEPLOY_EVIDENCE_FILE}"
    deployment_evidence_allowed_keys='evidence_format state operation target docker_context compose_project service_scope data_mode actor pid started_at ended_at final_state recovery_branch evidence_file'
    deployment_evidence_allowed_keys="${deployment_evidence_allowed_keys} desired_release desired_source_oid desired_config_revision desired_platform desired_tagged_image desired_exact_image desired_image_id"
    deployment_evidence_allowed_keys="${deployment_evidence_allowed_keys} previous_state previous_release previous_source_oid previous_image previous_image_id"
    deployment_evidence_allowed_keys="${deployment_evidence_allowed_keys} artifact_status backup_status backup_directory backup_created_at backup_application_revision schema_compatibility schema_recovery migration_status data_checks_status activation_status"
    deployment_evidence_allowed_keys="${deployment_evidence_allowed_keys} smoke_status smoke_endpoint smoke_connect_timeout_seconds smoke_request_timeout_seconds smoke_deadline_seconds smoke_health_status smoke_address_search_status"
    deployment_evidence_allowed_keys="${deployment_evidence_allowed_keys} exit_code failed_step handover_by handover_at"
    shell_runtime_key_value_update \
        release_fail 'deployment evidence' "${DEPLOY_EVIDENCE_FILE}" \
        evidence "${deployment_evidence_allowed_keys}" "$@"
)

deployment_evidence_publish() {
    deployment_evidence_publish_source=$1
    deployment_evidence_publish_destination=$2
    deployment_evidence_publish_temporary="${deployment_evidence_publish_destination}.tmp.$$"

    release_require_file "${deployment_evidence_publish_source}"
    [ ! -L "${deployment_evidence_publish_destination}" ] \
        || release_fail "deployment evidence destination must not be a symbolic link"
    [ ! -L "${deployment_evidence_publish_temporary}" ] \
        || release_fail "deployment evidence temporary file must not be a symbolic link"
    cp "${deployment_evidence_publish_source}" \
        "${deployment_evidence_publish_temporary}"
    mv "${deployment_evidence_publish_temporary}" \
        "${deployment_evidence_publish_destination}"
}

deployment_evidence_begin() {
    deployment_evidence_operation=$1
    deployment_evidence_scope=$2
    deployment_evidence_data_mode=$3
    deployment_evidence_identity_file=$4

    for deployment_evidence_command in date docker id mkdir mktemp mv; do
        release_require_command "${deployment_evidence_command}"
    done
    [ -n "${DEPLOY_LOCK_FILE:-}" ] \
        || release_fail "deployment evidence requires the target lock"

    deployment_evidence_state_directory=${DEPLOY_LOCK_FILE%/*}
    deployment_evidence_operations_directory="${deployment_evidence_state_directory}/operations"
    mkdir -p "${deployment_evidence_operations_directory}"
    [ ! -L "${deployment_evidence_operations_directory}" ] \
        || release_fail "deployment evidence directory must not be a symbolic link"

    deployment_evidence_started_at=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
    deployment_evidence_timestamp=$(date -u '+%Y%m%dT%H%M%SZ')
    DEPLOY_EVIDENCE_FILE="${deployment_evidence_operations_directory}/${deployment_evidence_timestamp}-${deployment_evidence_operation}-$$.env"
    [ ! -e "${DEPLOY_EVIDENCE_FILE}" ] \
        || release_fail "deployment evidence destination already exists"

    deployment_desired_release=$(release_env_value RELEASE_VERSION \
        "${deployment_evidence_identity_file}" || true)
    deployment_desired_source_oid=$(release_env_value RELEASE_SOURCE_OID \
        "${deployment_evidence_identity_file}" || true)
    [ -n "${deployment_desired_source_oid}" ] \
        || deployment_desired_source_oid=legacy-unbound
    deployment_desired_config_revision=$(release_env_value RELEASE_CONFIG_REVISION \
        "${deployment_evidence_identity_file}" || true)
    [ -n "${deployment_desired_config_revision}" ] \
        || deployment_desired_config_revision=legacy-unbound
    deployment_desired_platform=$(release_env_value DOCKER_PLATFORM \
        "${deployment_evidence_identity_file}" || true)
    [ -n "${deployment_desired_platform}" ] \
        || deployment_desired_platform=${DOCKER_PLATFORM:-linux/amd64}
    deployment_desired_prefix=$(release_env_value DOCKER_IMAGE_PREFIX \
        "${deployment_evidence_identity_file}" || true)
    deployment_desired_tag=$(release_env_value APP_IMAGE_TAG \
        "${deployment_evidence_identity_file}" || true)
    deployment_desired_digest=$(release_env_value APP_IMAGE_DIGEST \
        "${deployment_evidence_identity_file}" || true)
    deployment_desired_image_id=$(release_env_value APP_IMAGE_ID \
        "${deployment_evidence_identity_file}" || true)
    [ -n "${deployment_desired_image_id}" ] \
        || deployment_desired_image_id=legacy-unbound
    deployment_desired_tagged_image="${deployment_desired_prefix}/app:${deployment_desired_tag}"
    deployment_desired_exact_image="${deployment_desired_prefix}/app@${deployment_desired_digest}"

    deployment_previous_state=absent
    deployment_previous_release=none
    deployment_previous_source_oid=none
    deployment_previous_image=none
    deployment_previous_image_id=none
    # shellcheck disable=SC2154
    deployment_previous_container=$(COMPOSE_ENV_FILE="${COMPOSE_ENV_FILE:-config/prod.secrets.env}" \
        RELEASE_ENV_FILE="${deployment_evidence_identity_file}" \
        "${project_root}/docker/compose/prod.sh" ps --quiet app 2>/dev/null || true)
    if [ -n "${deployment_previous_container}" ]; then
        deployment_previous_state=running
        deployment_previous_release=$(docker inspect \
            --format '{{index .Config.Labels "org.opencontainers.image.version"}}' \
            "${deployment_previous_container}" 2>/dev/null || true)
        deployment_previous_source_oid=$(docker inspect \
            --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' \
            "${deployment_previous_container}" 2>/dev/null || true)
        deployment_previous_image=$(docker inspect --format '{{.Config.Image}}' \
            "${deployment_previous_container}" 2>/dev/null || true)
        deployment_previous_image_id=$(docker inspect --format '{{.Image}}' \
            "${deployment_previous_container}" 2>/dev/null || true)
    fi

    deployment_evidence_temporary=$(mktemp \
        "${deployment_evidence_operations_directory}/.operation-evidence.XXXXXX")
    {
        printf '%s\n' 'evidence_format=1'
        printf '%s\n' 'state=active'
        printf 'operation=%s\n' "${deployment_evidence_operation}"
        printf 'target=%s\n' "${DEPLOY_TARGET}"
        printf 'docker_context=%s\n' "${DEPLOY_EXPECTED_DOCKER_CONTEXT}"
        printf 'compose_project=%s\n' "$(release_env_value COMPOSE_PROJECT_NAME "${deployment_evidence_identity_file}" || true)"
        printf 'service_scope=%s\n' "${deployment_evidence_scope}"
        printf 'data_mode=%s\n' "${deployment_evidence_data_mode}"
        printf 'actor=%s\n' "$(id -un)"
        printf 'pid=%s\n' "$$"
        printf 'started_at=%s\n' "${deployment_evidence_started_at}"
        printf '%s\n' 'ended_at=pending'
        printf 'desired_release=%s\n' "${deployment_desired_release}"
        printf 'desired_source_oid=%s\n' "${deployment_desired_source_oid}"
        printf 'desired_config_revision=%s\n' "${deployment_desired_config_revision}"
        printf 'desired_platform=%s\n' "${deployment_desired_platform}"
        printf 'desired_tagged_image=%s\n' "${deployment_desired_tagged_image}"
        printf 'desired_exact_image=%s\n' "${deployment_desired_exact_image}"
        printf 'desired_image_id=%s\n' "${deployment_desired_image_id}"
        printf 'previous_state=%s\n' "${deployment_previous_state}"
        printf 'previous_release=%s\n' "${deployment_previous_release:-none}"
        printf 'previous_source_oid=%s\n' "${deployment_previous_source_oid:-none}"
        printf 'previous_image=%s\n' "${deployment_previous_image:-none}"
        printf 'previous_image_id=%s\n' "${deployment_previous_image_id:-none}"
        printf '%s\n' 'artifact_status=pending'
        printf '%s\n' 'backup_status=pending'
        printf '%s\n' 'migration_status=pending'
        printf '%s\n' 'data_checks_status=pending'
        printf '%s\n' 'activation_status=pending'
        printf '%s\n' 'smoke_status=pending'
        printf '%s\n' 'smoke_endpoint=pending'
        printf '%s\n' 'smoke_connect_timeout_seconds=pending'
        printf '%s\n' 'smoke_request_timeout_seconds=pending'
        printf '%s\n' 'smoke_deadline_seconds=pending'
        printf '%s\n' 'smoke_health_status=pending'
        printf '%s\n' 'smoke_address_search_status=pending'
        printf '%s\n' 'final_state=active'
        printf '%s\n' 'recovery_branch=undecided'
        printf 'evidence_file=%s\n' "${DEPLOY_EVIDENCE_FILE}"
    } >"${deployment_evidence_temporary}"
    mv "${deployment_evidence_temporary}" "${DEPLOY_EVIDENCE_FILE}"
    export DEPLOY_EVIDENCE_FILE
    echo "Deployment evidence started: ${DEPLOY_EVIDENCE_FILE}"
}

deployment_evidence_finish() {
    deployment_evidence_final_state=$1
    deployment_evidence_current_file="${DEPLOY_LOCK_FILE%/*}/current.env"
    deployment_evidence_previous_file="${DEPLOY_LOCK_FILE%/*}/previous.env"

    deployment_evidence_update \
        state success \
        ended_at "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" \
        final_state "${deployment_evidence_final_state}" \
        recovery_branch none
    if [ -f "${deployment_evidence_current_file}" ]; then
        deployment_evidence_publish "${deployment_evidence_current_file}" \
            "${deployment_evidence_previous_file}"
    fi
    deployment_evidence_publish "${DEPLOY_EVIDENCE_FILE}" \
        "${deployment_evidence_current_file}"
    echo "Deployment evidence completed: ${DEPLOY_EVIDENCE_FILE}"
}

deployment_evidence_fail() {
    deployment_evidence_exit_code=$1
    deployment_evidence_failed_step=$2
    deployment_evidence_mutation_started=$3
    deployment_evidence_recovery=$4

    if [ "${deployment_evidence_mutation_started}" = 1 ]; then
        deployment_evidence_failure_state=partial
        deployment_evidence_final_state=partial-unclassified
    else
        deployment_evidence_failure_state=aborted
        deployment_evidence_final_state=safe-abort
    fi
    deployment_evidence_update \
        state "${deployment_evidence_failure_state}" \
        ended_at "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" \
        exit_code "${deployment_evidence_exit_code}" \
        failed_step "${deployment_evidence_failed_step}" \
        final_state "${deployment_evidence_final_state}" \
        recovery_branch "${deployment_evidence_recovery}"

    if [ "${deployment_evidence_mutation_started}" = 1 ]; then
        deployment_evidence_partial_file="${DEPLOY_LOCK_FILE%/*}/partial.env"
        deployment_evidence_publish "${DEPLOY_EVIDENCE_FILE}" \
            "${deployment_evidence_partial_file}"
        echo "Deployment partial state requires handover: ${deployment_evidence_partial_file}" >&2
    else
        echo "Deployment aborted before target mutation: ${DEPLOY_EVIDENCE_FILE}" >&2
    fi
}
