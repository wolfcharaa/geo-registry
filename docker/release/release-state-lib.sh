#!/bin/sh

release_validate_phase_manifest_format() {
    case "$1" in
        1|2) ;;
        *) release_fail "unsupported phase manifest format" ;;
    esac
}

release_phase_manifest_expect() {
    release_phase_file=$1
    release_phase_key=$2
    release_phase_expected=$3

    release_phase_actual=$(release_manifest_value "${release_phase_key}" "${release_phase_file}" || true)
    [ "${release_phase_actual}" = "${release_phase_expected}" ] \
        || release_fail "phase binding ${release_phase_key} is ${release_phase_actual:-missing}, expected ${release_phase_expected}"
}

release_phase_manifest_update() (
    set -eu

    release_phase_file=$1
    shift
    release_phase_allowed_keys='phase phase_manifest_format platform release_config_revision release_oid release_version'
    release_phase_allowed_keys="${release_phase_allowed_keys} application_source_oid"
    release_phase_allowed_keys="${release_phase_allowed_keys} prepared_at prepared_by prepared_config_revision prepared_core_oid prepared_image_reference prepared_source_oid prepared_state prepared_worker_oid"
    release_phase_allowed_keys="${release_phase_allowed_keys} built_at built_by built_config_revision built_core_oid built_image_digest built_image_id built_image_reference built_source_oid built_state built_worker_oid"
    release_phase_allowed_keys="${release_phase_allowed_keys} frozen_artifact_env frozen_at frozen_base_oid frozen_by frozen_config_revision frozen_image_digest frozen_image_id frozen_image_reference frozen_source_oid frozen_state"
    release_phase_allowed_keys="${release_phase_allowed_keys} registry_assurance registry_config_revision registry_image_digest registry_image_id registry_image_reference registry_release_oid registry_state registry_verified_at registry_verified_by"
    release_phase_allowed_keys="${release_phase_allowed_keys} offline_payload_archive offline_payload_archive_sha256 offline_payload_archive_size offline_payload_assurance offline_payload_config_revision offline_payload_image_count offline_payload_image_digest offline_payload_image_id offline_payload_image_reference offline_payload_postgres_image_id offline_payload_postgres_image_reference offline_payload_release_oid offline_payload_scope offline_payload_state offline_payload_verified_at offline_payload_verified_by"
    release_phase_allowed_keys="${release_phase_allowed_keys} offline_export_artifact_identity_sha256 offline_export_bundle offline_export_bundle_sha256 offline_export_checksum_path offline_export_config_revision offline_export_data_bundle offline_export_image_count offline_export_manifest_sha256 offline_export_path offline_export_release_oid offline_export_scope offline_export_sha256 offline_export_size offline_export_state offline_export_verified_at offline_export_verified_by"
    if [ -f "${release_phase_file}" ]; then
        release_validate_phase_manifest_format \
            "$(release_manifest_value phase_manifest_format "${release_phase_file}" || true)"
    fi

    shell_runtime_key_value_update \
        release_fail 'phase manifest' "${release_phase_file}" phase \
        "${release_phase_allowed_keys}" "$@"
)

release_acquire_output_lock() {
    release_lock_file=$1
    release_lock_operation=$2
    release_lock_version=$3
    release_lock_platform=$4

    for release_lock_command in date flock id sed; do
        release_require_command "${release_lock_command}"
    done

    # Append mode avoids truncating active-owner evidence before the lock is held.
    exec 9>>"${release_lock_file}"
    if ! flock -n 9; then
        echo "Active release output lock: ${release_lock_file}" >&2
        sed -n '1,20p' "${release_lock_file}" >&2 || true
        release_fail "another release operation owns ${release_lock_version} (${release_lock_platform})"
    fi

    : >"${release_lock_file}"
    {
        printf '%s\n' 'lock_format=1'
        printf 'operation=%s\n' "${release_lock_operation}"
        printf 'release_version=%s\n' "${release_lock_version}"
        printf 'platform=%s\n' "${release_lock_platform}"
        printf 'pid=%s\n' "$$"
        printf 'actor=%s\n' "$(id -un)"
        printf 'started_at=%s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
    } >&9
}
