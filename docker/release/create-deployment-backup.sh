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

for command_name in docker mkdir; do
    release_require_command "${command_name}"
done

if [ "$(id -u)" -eq 0 ] || [ -n "${SUDO_USER:-}" ]; then
    release_fail "run as the deployment user, without sudo"
fi

secrets_file=$(release_resolve_path "${COMPOSE_ENV_FILE:-config/prod.secrets.env}")
release_require_file "${secrets_file}"
backup_root=$(release_resolve_path "${BACKUP_ROOT:-docker/data/backups}")
mkdir -p "${backup_root}"

container=$(COMPOSE_ENV_FILE="${secrets_file}" \
    "${project_root}/docker/compose/prod.sh" ps --quiet postgres)
backup_validate_container "${container}"

if [ "${DEPLOY_BACKUP_DRY_RUN:-0}" != 1 ]; then
    release_env_file=$(release_resolve_path \
        "${RELEASE_ENV_FILE:-docker/compose/prod/release.env}")
    deployment_require_confirmed_target \
        data-backup database backup "${release_env_file}"
    deployment_acquire_target_lock \
        data-backup database backup "${release_env_file}"
fi

BACKUP_DRY_RUN="${DEPLOY_BACKUP_DRY_RUN:-0}" \
BACKUP_COMPONENT=application-postgres \
BACKUP_ROOT="${backup_root}" \
BACKUP_CONTAINER="${container}" \
BACKUP_DATABASE_NAME=geo_registry \
BACKUP_DATABASE_USER=geo_registry \
    "${project_root}/docker/backup/create-postgres-backup.sh"
