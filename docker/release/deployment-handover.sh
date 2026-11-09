#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
# shellcheck disable=SC2034
project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)

# shellcheck disable=SC1091
. "${script_dir}/release-lib.sh"
# shellcheck disable=SC1091
. "${script_dir}/deployment-lib.sh"

if [ "$(id -u)" -eq 0 ] || [ -n "${SUDO_USER:-}" ]; then
    release_fail "run as the deployment user, without sudo"
fi

for command_name in date id mkdir readlink rm; do
    release_require_command "${command_name}"
done

secrets_file=$(release_resolve_path "${COMPOSE_ENV_FILE:-config/prod.secrets.env}")
source_env_file=$(release_resolve_path \
    "${RELEASE_ENV_FILE:-docker/compose/prod/release.env}")
release_require_file "${secrets_file}"
release_require_file "${source_env_file}"

release_version=$(release_env_value RELEASE_VERSION "${source_env_file}" || true)
release_validate_version "${release_version}"
artifact_directory=$(release_resolve_path \
    "${RELEASE_ARTIFACT_DIR:-docker/data/release/${release_version}}")
identity_file=$(release_identity_file "${source_env_file}" "${artifact_directory}")
release_require_file "${identity_file}"

recovery_branch=${DEPLOY_RECOVERY_BRANCH:-}
case "${recovery_branch}" in
    safe-abort|roll-forward|restore|manual-recovery) ;;
    *)
        release_fail "DEPLOY_RECOVERY_BRANCH must be safe-abort, roll-forward, restore or manual-recovery"
        ;;
esac

expected_confirmation="handover:${DEPLOY_TARGET:-}:${recovery_branch}"
[ "${DEPLOY_HANDOVER_CONFIRM:-}" = "${expected_confirmation}" ] \
    || release_fail "DEPLOY_HANDOVER_CONFIRM must exactly equal ${expected_confirmation}"

deployment_require_confirmed_target \
    deployment-handover recovery recovery "${identity_file}"
deployment_acquire_target_lock \
    deployment-handover recovery recovery "${identity_file}"

state_directory=${DEPLOY_LOCK_FILE%/*}
partial_file="${state_directory}/partial.env"
operations_directory="${state_directory}/operations"
handovers_directory="${state_directory}/handovers"
release_require_file "${partial_file}"
[ ! -L "${partial_file}" ] \
    || release_fail "deployment partial marker must not be a symbolic link"

evidence_file=$(release_env_value evidence_file "${partial_file}" || true)
[ -n "${evidence_file}" ] \
    || release_fail "deployment partial marker does not identify its evidence file"
[ ! -L "${evidence_file}" ] \
    || release_fail "deployment operation evidence must not be a symbolic link"
release_require_file "${evidence_file}"

canonical_operations_directory=$(readlink -f "${operations_directory}" 2>/dev/null || true)
canonical_evidence_file=$(readlink -f "${evidence_file}" 2>/dev/null || true)
[ -n "${canonical_operations_directory}" ] \
    || release_fail "cannot resolve the deployment operations directory"
[ "${canonical_evidence_file%/*}" = "${canonical_operations_directory}" ] \
    || release_fail "deployment partial marker points outside its target operations directory"

DEPLOY_EVIDENCE_FILE=${canonical_evidence_file}
export DEPLOY_EVIDENCE_FILE
handover_at=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
deployment_evidence_update \
    state handed-over \
    final_state recovery-handover \
    recovery_branch "${recovery_branch}" \
    handover_by "$(id -un)" \
    handover_at "${handover_at}"

mkdir -p "${handovers_directory}"
[ ! -L "${handovers_directory}" ] \
    || release_fail "deployment handover directory must not be a symbolic link"
handover_timestamp=$(date -u '+%Y%m%dT%H%M%SZ')
handover_file="${handovers_directory}/${handover_timestamp}-${recovery_branch}-$$.env"
[ ! -e "${handover_file}" ] \
    || release_fail "deployment handover destination already exists"
deployment_evidence_publish "${DEPLOY_EVIDENCE_FILE}" "${handover_file}"

# The exact marker was validated under the held target lock and is replaced by
# the durable handover record above. No container or data recovery is performed.
rm -f -- "${partial_file}"

echo "Deployment recovery handover recorded: ${handover_file}"
echo "Recovery branch: ${recovery_branch}"
echo "No rollback, restore or service mutation was executed."
