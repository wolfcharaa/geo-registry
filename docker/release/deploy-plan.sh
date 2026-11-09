#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)

# shellcheck disable=SC1091
. "${script_dir}/release-lib.sh"
# shellcheck disable=SC1091
. "${project_root}/docker/backup/backup-lib.sh"

for command_name in awk docker git grep id mktemp rm sort; do
    release_require_command "${command_name}"
done

if [ "$(id -u)" -eq 0 ] || [ -n "${SUDO_USER:-}" ]; then
    release_fail "run as the deployment user, without sudo"
fi

temporary_directory=$(mktemp -d)
cleanup() {
    rm -rf -- "${temporary_directory}"
}
trap cleanup EXIT HUP INT TERM

blockers=
add_blocker() {
    if [ -z "${blockers}" ]; then
        blockers=$1
    else
        blockers="${blockers},$1"
    fi
}

doctor_output="${temporary_directory}/doctor.env"
doctor_error="${temporary_directory}/doctor.err"
doctor_exit=0
DEPLOY_DOCTOR_EMBEDDED=1 DEPLOY_DOCTOR_KEY_PREFIX=doctor_ \
    "${script_dir}/deploy-doctor.sh" >"${doctor_output}" 2>"${doctor_error}" \
    || doctor_exit=$?
doctor_status=$(release_env_value doctor_status "${doctor_output}" || true)
if [ "${doctor_exit}" -ne 0 ] || [ "${doctor_status}" != ready ]; then
    add_blocker doctor-blocked
fi

data_mode=${DEPLOY_DATA_MODE:-inspect}
case "${data_mode}" in
    inspect|fresh|restore|update) ;;
    *) add_blocker data-mode-invalid ;;
esac

service_scope=${DEPLOY_SERVICE_SCOPE:-core}
case "${service_scope}" in
    core)
        services=postgres,app,worker-address,worker-runtime,maintenance
        ;;
    application)
        services=app,worker-address,worker-runtime,maintenance
        case "${data_mode}" in
            fresh|restore) add_blocker application-scope-excludes-postgres ;;
        esac
        ;;
    *)
        services=unavailable
        add_blocker service-scope-invalid
        ;;
esac

source_env_file=$(release_resolve_path \
    "${RELEASE_ENV_FILE:-${RELEASE_SOURCE_ENV_FILE:-docker/compose/prod/release.env}}")
compose_model=$(release_resolve_path \
    "${PRODUCTION_COMPOSE_MODEL:-docker/compose/prod/compose.yaml}")
secrets_file=$(release_resolve_path "${COMPOSE_ENV_FILE:-config/prod.secrets.env}")

desired_release=unavailable
desired_source_oid=unavailable
desired_config_revision=unavailable
desired_platform=${DOCKER_PLATFORM:-linux/amd64}
desired_tagged_image=unavailable
desired_exact_image=unavailable
desired_image_id=unavailable
desired_identity_assurance=unavailable
desired_image_state=not-checked
identity_file=unavailable
source_identity_format=unavailable

if [ -f "${source_env_file}" ] && [ -f "${compose_model}" ]; then
    source_identity_format=$(release_env_value RELEASE_IDENTITY_FORMAT \
        "${source_env_file}" || true)
    [ -n "${source_identity_format}" ] || source_identity_format=1
    case "${source_identity_format}" in
        1|2) ;;
        *) add_blocker source-identity-format-unsupported ;;
    esac

    desired_release=$(release_env_value RELEASE_VERSION "${source_env_file}" || true)
    image_prefix=$(release_env_value DOCKER_IMAGE_PREFIX "${source_env_file}" || true)
    image_tag=$(release_env_value APP_IMAGE_TAG "${source_env_file}" || true)
    if ! printf '%s\n' "${desired_release}" \
        | grep -Eq '^v[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$'; then
        add_blocker desired-release-invalid
    fi
    if ! (release_validate_prefix "${image_prefix}") >/dev/null 2>&1; then
        add_blocker desired-image-prefix-invalid
    fi
    case "${image_tag}" in
        ''|*' '*|*/*|*@*|*:*) add_blocker desired-image-tag-invalid ;;
    esac
    desired_tagged_image="${image_prefix}/app:${image_tag}"
    desired_config_revision=$(release_config_revision "${compose_model}" "${source_env_file}")

    release_version_for_path=${desired_release:-unknown}
    artifact_directory=$(release_resolve_path \
        "${RELEASE_ARTIFACT_DIR:-docker/data/release/${release_version_for_path}}")
    identity_file=$(release_identity_file "${source_env_file}" "${artifact_directory}")
    if [ -f "${identity_file}" ]; then
        image_digest=$(release_env_value APP_IMAGE_DIGEST "${identity_file}" || true)
        if (release_validate_digest "${image_digest}") >/dev/null 2>&1; then
            desired_exact_image="${image_prefix}/app@${image_digest}"
        else
            add_blocker desired-image-digest-invalid
        fi

        if [ "${source_identity_format}" = 2 ]; then
            desired_source_oid=$(release_env_value RELEASE_SOURCE_OID \
                "${identity_file}" || true)
            desired_config_revision_from_artifact=$(release_env_value \
                RELEASE_CONFIG_REVISION "${identity_file}" || true)
            desired_platform=$(release_env_value DOCKER_PLATFORM \
                "${identity_file}" || true)
            desired_image_id=$(release_env_value APP_IMAGE_ID "${identity_file}" || true)
            current_source_oid=$(git -C "${project_root}" rev-parse HEAD 2>/dev/null || true)
            if ! (release_validate_artifact_identity \
                "${identity_file}" "${desired_release}" "${desired_platform}" \
                "${current_source_oid}" "${desired_config_revision}") >/dev/null 2>&1; then
                add_blocker artifact-identity-mismatch
            fi
            if [ "${desired_config_revision_from_artifact}" != "${desired_config_revision}" ]; then
                add_blocker artifact-config-revision-mismatch
            fi
            desired_identity_assurance=source-config-digest-image-id
        else
            desired_source_oid=legacy-unbound
            desired_image_id=legacy-unbound
            desired_identity_assurance=legacy-digest-only
        fi
    else
        add_blocker artifact-identity-missing
    fi
else
    add_blocker desired-source-inputs-missing
fi

doctor_engine_state=$(release_env_value doctor_engine_state "${doctor_output}" || true)
if [ "${doctor_engine_state}" = available ] \
    && [ "${desired_exact_image}" != unavailable ]; then
    if docker image inspect "${desired_exact_image}" >/dev/null 2>&1; then
        desired_image_state=loaded
        actual_desired_image_id=$(docker image inspect --format '{{.Id}}' \
            "${desired_exact_image}" 2>/dev/null || true)
        if [ "${desired_image_id}" != legacy-unbound ] \
            && [ "${actual_desired_image_id}" != "${desired_image_id}" ]; then
            desired_image_state=loaded-identity-mismatch
            add_blocker desired-local-image-mismatch
        fi
    else
        desired_image_state=not-loaded
    fi
fi

rendered_config_checksum=unavailable
config_hashes="${temporary_directory}/compose.hashes"
sorted_config_hashes="${temporary_directory}/compose.hashes.sorted"
if [ "${doctor_status}" = ready ] && [ -f "${identity_file}" ]; then
    if COMPOSE_ENV_FILE="${secrets_file}" \
        RELEASE_ENV_FILE="${source_env_file}" \
        RELEASE_ARTIFACT_ENV_FILE="${identity_file}" \
        PRODUCTION_COMPOSE_MODEL="${compose_model}" \
        "${project_root}/docker/compose/prod.sh" \
        config --hash='*' >"${config_hashes}" 2>/dev/null \
        && [ -s "${config_hashes}" ]; then
        sort "${config_hashes}" >"${sorted_config_hashes}"
        rendered_config_checksum="sha256:$(release_sha256 "${sorted_config_hashes}")"
    else
        add_blocker rendered-config-unavailable
    fi
fi

current_state=not-checked
current_container=none
current_image=none
current_image_id=none
current_release=none
current_source_oid=none
current_relation=unknown
if [ "${doctor_status}" = ready ] && [ -f "${identity_file}" ]; then
    current_container=$(COMPOSE_ENV_FILE="${secrets_file}" \
        RELEASE_ENV_FILE="${source_env_file}" \
        RELEASE_ARTIFACT_ENV_FILE="${identity_file}" \
        PRODUCTION_COMPOSE_MODEL="${compose_model}" \
        "${project_root}/docker/compose/prod.sh" ps --quiet app 2>/dev/null || true)
    if [ -n "${current_container}" ]; then
        current_state=running
        current_image=$(docker inspect --format '{{.Config.Image}}' \
            "${current_container}" 2>/dev/null || true)
        current_image_id=$(docker inspect --format '{{.Image}}' \
            "${current_container}" 2>/dev/null || true)
        current_release=$(docker inspect \
            --format '{{index .Config.Labels "org.opencontainers.image.version"}}' \
            "${current_container}" 2>/dev/null || true)
        current_source_oid=$(docker inspect \
            --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' \
            "${current_container}" 2>/dev/null || true)
        if [ -z "${current_image}" ] || [ -z "${current_image_id}" ]; then
            current_state=identity-unavailable
            add_blocker current-identity-unavailable
        elif [ "${current_image}" = "${desired_tagged_image}" ] \
            || { [ "${desired_image_id}" != legacy-unbound ] \
                && [ "${current_image_id}" = "${desired_image_id}" ]; }; then
            current_relation=desired
        else
            current_relation=different
        fi
    else
        current_state=absent
        current_container=none
        if [ "${data_mode}" = update ]; then
            add_blocker update-current-deployment-missing
        fi
    fi
fi

backup_directory=${DEPLOY_BACKUP_DIR:-}
backup_gate=not-required
backup_revision=none
case "${data_mode}" in
    restore|update)
        if [ -z "${backup_directory}" ]; then
            backup_directory='unset'
            backup_gate=missing
            add_blocker backup-required
        else
            backup_directory=$(release_resolve_path "${backup_directory}")
            backup_validation_error="${temporary_directory}/backup.err"
            if (backup_validate_bundle "${backup_directory}") \
                > /dev/null 2>"${backup_validation_error}"; then
                backup_manifest="${backup_directory}/manifest.txt"
                backup_component=$(backup_manifest_value component \
                    "${backup_manifest}" || true)
                backup_revision=$(backup_manifest_value application_revision \
                    "${backup_manifest}" || true)
                if [ "${backup_component}" != application-postgres ]; then
                    backup_gate=wrong-component
                    add_blocker backup-component-mismatch
                elif [ "${data_mode}" = update ] \
                    && [ "${current_source_oid}" != "${backup_revision}" ]; then
                    backup_gate=current-identity-mismatch
                    add_blocker backup-current-identity-mismatch
                else
                    backup_gate=verified
                fi
            else
                backup_gate=invalid
                add_blocker backup-invalid
            fi
        fi
        ;;
    inspect) backup_gate=not-evaluated ;;
esac

plan_status=ready
if [ -n "${blockers}" ]; then
    plan_status=blocked
else
    blockers=none
fi

next_action=review-only
if [ "${plan_status}" = blocked ]; then
    next_action=resolve-blockers
elif [ "${desired_image_state}" = not-loaded ]; then
    next_action=acquire-desired-artifact
elif [ "${data_mode}" != inspect ]; then
    next_action="execute-confirmed-${data_mode}"
fi

echo "Deployment plan (read-only)"
cat "${doctor_output}"
printf 'status=%s\n' "${plan_status}"
printf 'data_mode=%s\n' "${data_mode}"
printf 'service_scope=%s\n' "${service_scope}"
printf 'services=%s\n' "${services}"
printf 'source_identity_format=%s\n' "${source_identity_format}"
printf 'desired_release=%s\n' "${desired_release}"
printf 'desired_source_oid=%s\n' "${desired_source_oid}"
printf 'desired_config_revision=%s\n' "${desired_config_revision}"
printf 'desired_platform=%s\n' "${desired_platform}"
printf 'desired_tagged_image=%s\n' "${desired_tagged_image}"
printf 'desired_exact_image=%s\n' "${desired_exact_image}"
printf 'desired_image_id=%s\n' "${desired_image_id}"
printf 'desired_identity_assurance=%s\n' "${desired_identity_assurance}"
printf 'desired_image_state=%s\n' "${desired_image_state}"
printf 'rendered_config_checksum=%s\n' "${rendered_config_checksum}"
printf 'current_state=%s\n' "${current_state}"
printf 'current_container=%s\n' "${current_container}"
printf 'current_release=%s\n' "${current_release:-none}"
printf 'current_source_oid=%s\n' "${current_source_oid:-none}"
printf 'current_image=%s\n' "${current_image:-none}"
printf 'current_image_id=%s\n' "${current_image_id:-none}"
printf 'current_relation=%s\n' "${current_relation}"
printf 'backup_directory=%s\n' "${backup_directory:-unset}"
printf 'backup_gate=%s\n' "${backup_gate}"
printf 'backup_revision=%s\n' "${backup_revision}"
printf 'blockers=%s\n' "${blockers}"
printf 'next_action=%s\n' "${next_action}"
printf '%s\n' 'external_mutations=none'
printf '%s\n' 'last_safe_abort=before-artifact-acquire'

if [ "${plan_status}" != ready ]; then
    echo "Deployment plan is blocked; no pull, load, container, data or evidence mutation was attempted." >&2
    exit 1
fi
