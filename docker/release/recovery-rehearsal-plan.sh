#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)

# shellcheck disable=SC1091
. "${script_dir}/release-lib.sh"
# shellcheck disable=SC1091
. "${script_dir}/deployment-lib.sh"
# shellcheck disable=SC1091
. "${project_root}/docker/backup/backup-lib.sh"

for command_name in awk cat mktemp rm; do
    release_require_command "${command_name}"
done

if [ "$(id -u)" -eq 0 ] || [ -n "${SUDO_USER:-}" ]; then
    release_fail "run as the deployment user, without sudo"
fi

source_target=${RECOVERY_SOURCE_TARGET:-}
deployment_validate_label "${source_target}" \
    || release_fail "RECOVERY_SOURCE_TARGET must name the source deployment"
target=${DEPLOY_TARGET:-}
deployment_validate_label "${target}" \
    || release_fail "DEPLOY_TARGET must name the isolated rehearsal target"
[ "${source_target}" != "${target}" ] \
    || release_fail "recovery rehearsal target must differ from RECOVERY_SOURCE_TARGET"

off_host_copy=${RECOVERY_OFF_HOST_COPY_REFERENCE:-}
deployment_validate_label "${off_host_copy}" \
    || release_fail "RECOVERY_OFF_HOST_COPY_REFERENCE must be a non-secret evidence label"

nominatim_strategy=${RECOVERY_NOMINATIM_STRATEGY:-}
case "${nominatim_strategy}" in
    restore|rebuild) ;;
    *) release_fail "RECOVERY_NOMINATIM_STRATEGY must be restore or rebuild" ;;
esac

observation_seconds=${RECOVERY_OBSERVATION_SECONDS:-300}
case "${observation_seconds}" in
    ''|*[!0-9]*) release_fail "RECOVERY_OBSERVATION_SECONDS must be a positive integer" ;;
esac
[ "${observation_seconds}" -gt 0 ] \
    || release_fail "RECOVERY_OBSERVATION_SECONDS must be a positive integer"

backup_directory=${DEPLOY_BACKUP_DIR:-}
[ -n "${backup_directory}" ] \
    || release_fail "DEPLOY_BACKUP_DIR must select the recovery rehearsal backup"
backup_directory=$(release_resolve_path "${backup_directory}")
backup_validate_bundle "${backup_directory}"
backup_manifest="${backup_directory}/manifest.txt"
backup_created_at=$(backup_manifest_value created_at "${backup_manifest}" || true)
backup_revision=$(backup_manifest_value application_revision \
    "${backup_manifest}" || true)
backup_bytes=$(backup_manifest_value dump_bytes "${backup_manifest}" || true)
backup_sha256=$(backup_manifest_value dump_sha256 "${backup_manifest}" || true)
[ "$(backup_manifest_value component "${backup_manifest}" || true)" = application-postgres ] \
    || release_fail "recovery rehearsal requires an application-postgres backup"
[ -n "${backup_created_at}" ] \
    || release_fail "recovery rehearsal backup creation time is missing"
backup_validate_revision BACKUP_APPLICATION_REVISION "${backup_revision}"
backup_validate_positive_integer BACKUP_DUMP_BYTES "${backup_bytes}"
backup_validate_sha256 BACKUP_DUMP_SHA256 "${backup_sha256}"

temporary_directory=$(mktemp -d)
cleanup_recovery_rehearsal_plan() {
    rm -rf -- "${temporary_directory}"
}
trap cleanup_recovery_rehearsal_plan EXIT HUP INT TERM

deployment_plan="${temporary_directory}/deployment-plan.env"
if ! DEPLOY_DATA_MODE=restore \
    DEPLOY_SERVICE_SCOPE=core \
    DEPLOY_SCHEMA_COMPATIBILITY='' \
    DEPLOY_BACKUP_DIR="${backup_directory}" \
        "${script_dir}/deploy-plan.sh" >"${deployment_plan}" 2>&1; then
    cat "${deployment_plan}" >&2
    release_fail "recovery rehearsal deployment plan is blocked"
fi
[ "$(release_env_value status "${deployment_plan}" || true)" = ready ] \
    || release_fail "recovery rehearsal deployment plan is not ready"

current_state=$(release_env_value current_state "${deployment_plan}" || true)
[ "${current_state}" = absent ] \
    || release_fail "recovery rehearsal target already has an application deployment"

data_status="${temporary_directory}/data-status.txt"
if ! DEPLOY_DATA_REQUIRE_READY=0 \
    "${script_dir}/deployment-data-status.sh" >"${data_status}" 2>&1; then
    cat "${data_status}" >&2
    release_fail "recovery rehearsal target data state is unsafe"
fi
database_state=$(awk -F': ' '$1 == "State" {print $2; exit}' "${data_status}")
case "${database_state}" in
    database-absent) database_absence_gate=verified ;;
    postgres-not-running) database_absence_gate=deferred-to-restore-preflight ;;
    *)
        cat "${data_status}" >&2
        release_fail "recovery rehearsal target database is not empty: ${database_state:-unknown}"
        ;;
esac

state_root=$(release_resolve_path "${DEPLOY_STATE_ROOT:-docker/data/deployment}")
evidence_directory="${state_root}/${target}/recovery-rehearsals"

echo "Recovery rehearsal plan (read-only)"
cat "${deployment_plan}"
printf '%s\n' 'recovery_plan_format=1'
printf '%s\n' 'recovery_status=ready'
printf 'source_target=%s\n' "${source_target}"
printf 'rehearsal_target=%s\n' "${target}"
printf 'recovery_backup_directory=%s\n' "${backup_directory}"
printf 'recovery_backup_created_at=%s\n' "${backup_created_at}"
printf 'recovery_backup_application_revision=%s\n' "${backup_revision}"
printf 'recovery_backup_dump_bytes=%s\n' "${backup_bytes}"
printf 'recovery_backup_dump_sha256=%s\n' "${backup_sha256}"
printf 'off_host_copy_reference=%s\n' "${off_host_copy}"
printf 'database_state=%s\n' "${database_state}"
printf 'database_absence_gate=%s\n' "${database_absence_gate}"
printf 'nominatim_strategy=%s\n' "${nominatim_strategy}"
printf 'observation_seconds=%s\n' "${observation_seconds}"
printf 'evidence_directory=%s\n' "${evidence_directory}"
printf '%s\n' 'required_phases=backup-verify,restore,migrate,gar-gates,opensearch-rebuild,address-smoke,nominatim-gate,record'
printf '%s\n' 'rpo_measurement=backup-created-at-to-rehearsal-cutover'
printf '%s\n' 'rto_measurement=restore-start-to-bounded-smoke-pass'
printf '%s\n' 'recovery_last_safe_abort=before-data-restore'
printf '%s\n' 'recovery_external_mutations=none'
printf '%s\n' 'recovery_next_action=review-plan-then-run-separately-authorized-rehearsal'
