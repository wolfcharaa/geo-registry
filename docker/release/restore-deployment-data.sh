#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)

# shellcheck disable=SC1091
. "${script_dir}/release-lib.sh"
# shellcheck disable=SC1091
. "${project_root}/docker/backup/backup-lib.sh"

for command_name in awk docker mkdir tr; do
    release_require_command "${command_name}"
done

if [ "$(id -u)" -eq 0 ] || [ -n "${SUDO_USER:-}" ]; then
    release_fail "run as the deployment user, without sudo"
fi

assume_yes=0
configured_backup=
configured_release=
backup_root="${project_root}/docker/data/backups"
for argument in "$@"; do
    case "${argument}" in
        --yes) assume_yes=1 ;;
        --backup-dir=*) configured_backup=${argument#--backup-dir=} ;;
        --release-dir=*) configured_release=${argument#--release-dir=} ;;
        --backup-root=*) backup_root=${argument#--backup-root=} ;;
        *) release_fail "usage: restore-deployment-data.sh [--yes] [--backup-dir=PATH | --release-dir=PATH] [--backup-root=PATH]" ;;
    esac
done
[ -z "${configured_backup}" ] || [ -z "${configured_release}" ] \
    || release_fail "--backup-dir and --release-dir are mutually exclusive"

secrets_file=$(release_resolve_path "${COMPOSE_ENV_FILE:-config/prod.secrets.env}")
release_require_file "${secrets_file}"
database_password=$(release_env_value GEO_REGISTRY_DATABASE_PASSWORD "${secrets_file}" || true)
case "${database_password}" in
    ''|*[!A-Za-z0-9._~-]*)
        release_fail "GEO_REGISTRY_DATABASE_PASSWORD must use only A-Z, a-z, digits, dot, underscore, tilde or hyphen"
        ;;
esac

database_name=geo_registry
database_user=geo_registry
admin_user=postgres
backup_validate_identifier DATABASE_NAME "${database_name}"
backup_validate_identifier DATABASE_USER "${database_user}"

backup_root=$(release_resolve_path "${backup_root}")
mkdir -p "${backup_root}"
[ -w "${backup_root}" ] || release_fail "backup root is not writable: ${backup_root}"

if [ -n "${configured_backup}" ]; then
    backup_directory=$(release_resolve_path "${configured_backup}")
else
    backup_directory=
    backup_count=0
    for manifest_path in "${backup_root}"/application-postgres-*/manifest.txt; do
        [ -f "${manifest_path}" ] || continue
        backup_directory=${manifest_path%/manifest.txt}
        backup_count=$((backup_count + 1))
    done
    [ "${backup_count}" -le 1 ] \
        || release_fail "several extracted backups exist; select one with --backup-dir=PATH"
fi

if [ -z "${backup_directory}" ]; then
    if [ -n "${configured_release}" ]; then
        release_directory=$(release_resolve_path "${configured_release}")
    else
        release_directory=
        release_count=0
        for manifest_path in "${project_root}"/docker/data/release/*/source-manifest.txt; do
            [ -f "${manifest_path}" ] || continue
            [ "$(release_manifest_value data_bundle "${manifest_path}" || true)" = application-postgres ] \
                || continue
            release_directory=${manifest_path%/source-manifest.txt}
            release_count=$((release_count + 1))
        done
        [ "${release_count}" -eq 1 ] \
            || release_fail "expected exactly one full-data release; select one with --release-dir=PATH"
    fi

    release_version=$(release_manifest_value release_version "${release_directory}/source-manifest.txt" || true)
    release_validate_version "${release_version}"
    backup_directory="${backup_root}/application-postgres-${release_version}"
    if [ ! -d "${backup_directory}" ]; then
        RELEASE_EXTRACT_DIR="${release_directory}" \
            BACKUP_ROOT="${backup_root}" \
            "${script_dir}/extract-release-data.sh"
    fi
fi

backup_require_directory BACKUP_DIR "${backup_directory}"
[ -f "${backup_directory}/manifest.txt" ] \
    || release_fail "backup manifest is missing: ${backup_directory}/manifest.txt"
[ -f "${backup_directory}/application-postgres.dump" ] \
    || release_fail "backup dump is missing: ${backup_directory}/application-postgres.dump"

COMPOSE_ENV_FILE="${secrets_file}" "${project_root}/docker/compose/prod.sh" \
    stop app worker-address worker-runtime >/dev/null 2>&1 || true

echo "Preparing the PostgreSQL service declared in production Compose."
COMPOSE_ENV_FILE="${secrets_file}" "${project_root}/docker/compose/prod.sh" pull postgres
COMPOSE_ENV_FILE="${secrets_file}" "${project_root}/docker/compose/prod.sh" \
    up --detach --wait postgres

container=$(COMPOSE_ENV_FILE="${secrets_file}" \
    "${project_root}/docker/compose/prod.sh" ps --quiet postgres)
backup_validate_container "${container}"

database_exists=$(docker exec "${container}" \
    psql --no-psqlrc --tuples-only --no-align --set=ON_ERROR_STOP=1 \
    --username "${admin_user}" --dbname postgres \
    --command "SELECT count(*) FROM pg_database WHERE datname = '${database_name}';" \
    | tr -d '[:space:]')
case "${database_exists}" in
    0) ;;
    1)
        active_restore_sessions=$(docker exec "${container}" \
            psql --no-psqlrc --tuples-only --no-align --set=ON_ERROR_STOP=1 \
            --username "${admin_user}" --dbname postgres \
            --command "SELECT count(*) FROM pg_stat_activity WHERE datname = '${database_name}' AND application_name = 'pg_restore';" \
            | tr -d '[:space:]')
        if [ "${active_restore_sessions}" -gt 0 ]; then
            release_fail "restore is already active for ${database_name}; run make data-restore-status and wait instead of retrying"
        fi
        release_fail "database ${database_name} already exists; run make data-restore-status and validate it with make deploy-start instead of retrying in place"
        ;;
    *) release_fail "cannot determine target database state" ;;
esac

role_exists=$(docker exec "${container}" \
    psql --no-psqlrc --tuples-only --no-align --set=ON_ERROR_STOP=1 \
    --username "${admin_user}" --dbname postgres \
    --command "SELECT count(*) FROM pg_roles WHERE rolname = '${database_user}';" \
    | tr -d '[:space:]')
case "${role_exists}" in
    0)
        docker exec "${container}" \
            psql --no-psqlrc --set=ON_ERROR_STOP=1 \
            --username "${admin_user}" --dbname postgres \
            --command "CREATE ROLE ${database_user} LOGIN SUPERUSER PASSWORD '${database_password}';"
        ;;
    1)
        docker exec "${container}" \
            psql --no-psqlrc --set=ON_ERROR_STOP=1 \
            --username "${admin_user}" --dbname postgres \
            --command "ALTER ROLE ${database_user} LOGIN SUPERUSER PASSWORD '${database_password}';"
        ;;
    *) release_fail "cannot determine managed database owner role state" ;;
esac

demote_owner() {
    docker exec "${container}" \
        psql --no-psqlrc --set=ON_ERROR_STOP=1 \
        --username "${admin_user}" --dbname postgres \
        --command "ALTER ROLE ${database_user} NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION;" \
        >/dev/null 2>&1 \
        || echo "Warning: failed to remove temporary elevated privileges from ${database_user}." >&2
}
cleanup_owner() {
    cleanup_status=$?
    trap - EXIT HUP INT TERM
    demote_owner
    exit "${cleanup_status}"
}
trap cleanup_owner EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

echo "Backup: ${backup_directory}"
echo "Target container: ${container}"
echo "New database: ${database_name}"
echo "The restore command will verify bytes, SHA-256, pg_restore TOC and PostgreSQL major before creating the database."

if [ "${assume_yes}" -ne 1 ]; then
    [ -t 0 ] || release_fail "interactive confirmation is unavailable; rerun with DEPLOY_RESTORE_FLAGS=--yes"
    printf 'Type the new database name "%s" to start the restore: ' "${database_name}"
    IFS= read -r confirmation
    [ "${confirmation}" = "${database_name}" ] || release_fail "restore confirmation did not match"
fi

BACKUP_DIR="${backup_directory}" \
    RESTORE_CONTAINER="${container}" \
    RESTORE_DATABASE="${database_name}" \
    RESTORE_DATABASE_OWNER="${database_user}" \
    RESTORE_ADMIN_USER="${admin_user}" \
    RESTORE_CONFIRM="create:${container}:${database_name}" \
    "${project_root}/docker/backup/restore-postgres-shadow.sh"

demote_owner
trap - EXIT HUP INT TERM

echo "Release data is restored into PostgreSQL database: ${database_name}"
echo "No session variables or manual DSN edits are required."
echo "Validate data and start the complete stack with: make deploy-start"
