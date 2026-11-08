#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)

# shellcheck disable=SC1091
. "${script_dir}/backup-lib.sh"

for command_name in awk date docker sha256sum sleep tr wc; do
    backup_require_command "${command_name}"
done

if [ "$(id -u)" -eq 0 ] || [ -n "${SUDO_USER:-}" ]; then
    backup_fail "run as the deployment user, without sudo"
fi

backup_directory=${BACKUP_DIR:-}
echo "Restore verification phase: checking manifest, dump bytes and SHA-256."
backup_validate_bundle "${backup_directory}"
echo "Restore verification completed; checking pg_restore TOC and target PostgreSQL."
manifest_path="${backup_directory}/manifest.txt"
component=$(backup_manifest_value component "${manifest_path}" || true)
backup_validate_component "${component}"
dump_file=$(backup_manifest_value dump_file "${manifest_path}")
dump_path="${backup_directory}/${dump_file}"

container=${RESTORE_CONTAINER:-}
backup_validate_container "${container}"
target_database=${RESTORE_DATABASE:-}
target_owner=${RESTORE_DATABASE_OWNER:-}
admin_database=${RESTORE_ADMIN_DATABASE:-postgres}
admin_user=${RESTORE_ADMIN_USER:-${target_owner}}
backup_validate_identifier RESTORE_DATABASE "${target_database}"
backup_validate_identifier RESTORE_DATABASE_OWNER "${target_owner}"
backup_validate_identifier RESTORE_ADMIN_DATABASE "${admin_database}"
backup_validate_identifier RESTORE_ADMIN_USER "${admin_user}"
progress_interval=${RESTORE_PROGRESS_INTERVAL_SECONDS:-30}
backup_validate_positive_integer RESTORE_PROGRESS_INTERVAL_SECONDS "${progress_interval}"

case "${component}" in
    application-postgres) container_user=${RESTORE_CONTAINER_USER:-} ;;
    nominatim) container_user=${RESTORE_CONTAINER_USER:-nominatim} ;;
esac

target_psql() {
    restore_database=$1
    restore_query=$2

    if [ -n "${container_user}" ]; then
        docker exec --user "${container_user}" "${container}" \
            psql --no-psqlrc --tuples-only --no-align --set=ON_ERROR_STOP=1 \
            --username "${admin_user}" --dbname "${restore_database}" --command "${restore_query}"
        return
    fi

    docker exec "${container}" \
        psql --no-psqlrc --tuples-only --no-align --set=ON_ERROR_STOP=1 \
        --username "${admin_user}" --dbname "${restore_database}" --command "${restore_query}"
}

target_createdb() {
    if [ -n "${container_user}" ]; then
        docker exec --user "${container_user}" "${container}" \
            createdb --no-password --username "${admin_user}" --owner "${target_owner}" \
            --template template0 "${target_database}"
        return
    fi

    docker exec "${container}" \
        createdb --no-password --username "${admin_user}" --owner "${target_owner}" \
        --template template0 "${target_database}"
}

target_restore() {
    if [ -n "${container_user}" ]; then
        docker exec --user "${container_user}" --interactive "${container}" \
            pg_restore --no-password --exit-on-error --no-owner --no-privileges \
            --username "${admin_user}" --role "${target_owner}" --dbname "${target_database}" \
            <"${dump_path}"
        return
    fi

    docker exec --interactive "${container}" \
        pg_restore --no-password --exit-on-error --no-owner --no-privileges \
        --username "${admin_user}" --role "${target_owner}" --dbname "${target_database}" \
        <"${dump_path}"
}

restore_monitor_pid=
stop_restore_monitor() {
    [ -n "${restore_monitor_pid}" ] || return 0
    kill "${restore_monitor_pid}" >/dev/null 2>&1 || true
    wait "${restore_monitor_pid}" >/dev/null 2>&1 || true
    restore_monitor_pid=
}
monitor_restore() {
    monitor_parent_pid=$1
    monitor_started_at=$2

    while kill -0 "${monitor_parent_pid}" >/dev/null 2>&1; do
        sleep "${progress_interval}"
        kill -0 "${monitor_parent_pid}" >/dev/null 2>&1 || break

        monitor_now=$(date +%s)
        monitor_elapsed=$((monitor_now - monitor_started_at))
        monitor_summary=$(target_psql "${target_database}" "
SELECT pg_size_pretty(pg_database_size(current_database()))
    || ' relations=' || count(*)
FROM pg_class AS c
JOIN pg_namespace AS n ON n.oid = c.relnamespace
WHERE n.nspname NOT IN ('pg_catalog', 'information_schema')
  AND c.relkind IN ('r', 'p');
" 2>/dev/null || true)
        monitor_activity=$(target_psql "${admin_database}" "
SELECT 'state=' || state
    || ' wait=' || COALESCE(wait_event_type || '/' || wait_event, 'cpu')
    || ' current_seconds=' || EXTRACT(epoch FROM (clock_timestamp() - query_start))::bigint
    || ' sql=' || left(replace(replace(query, chr(10), ' '), chr(13), ' '), 100)
FROM pg_stat_activity
WHERE datname = '${target_database}'
  AND application_name = 'pg_restore'
ORDER BY query_start
LIMIT 1;
" 2>/dev/null || true)
        [ -n "${monitor_summary}" ] || monitor_summary='database=unavailable'
        [ -n "${monitor_activity}" ] || monitor_activity='state=finishing-or-disconnected'
        printf 'Restore progress: elapsed_seconds=%s database=%s %s\n' \
            "${monitor_elapsed}" "${monitor_summary}" "${monitor_activity}"
    done
}

trap stop_restore_monitor EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

docker exec --interactive "${container}" pg_restore --list <"${dump_path}" >/dev/null
source_version_num=$(backup_manifest_value server_version_num "${manifest_path}" || true)
backup_validate_positive_integer server_version_num "${source_version_num}"
target_version_num=$(target_psql "${admin_database}" 'SHOW server_version_num;' | tr -d '[:space:]')
backup_validate_positive_integer target_server_version_num "${target_version_num}"
source_major=$((source_version_num / 10000))
target_major=$((target_version_num / 10000))
[ "${target_major}" -ge "${source_major}" ] \
    || backup_fail "target PostgreSQL major ${target_major} is older than source major ${source_major}"

role_exists=$(target_psql "${admin_database}" "SELECT count(*) FROM pg_roles WHERE rolname = '${target_owner}';" | tr -d '[:space:]')
[ "${role_exists}" = 1 ] || backup_fail "target owner role does not exist: ${target_owner}"
database_exists=$(target_psql "${admin_database}" "SELECT count(*) FROM pg_database WHERE datname = '${target_database}';" | tr -d '[:space:]')
[ "${database_exists}" = 0 ] \
    || backup_fail "target database already exists; in-place restore is forbidden: ${target_database}"

echo "Shadow restore preflight passed."
echo "Component: ${component}"
echo "Target database: ${target_database}"
echo "Source PostgreSQL major: ${source_major}"
echo "Target PostgreSQL major: ${target_major}"

if [ "${RESTORE_DRY_RUN:-0}" = 1 ]; then
    echo "Dry-run leaves the target cluster unchanged."
    exit 0
fi

expected_confirmation="create:${container}:${target_database}"
[ "${RESTORE_CONFIRM:-}" = "${expected_confirmation}" ] \
    || backup_fail "set RESTORE_CONFIRM=${expected_confirmation} to create the shadow database"

target_createdb
echo "Created empty shadow database: ${target_database}"
echo "If restore fails, the new database is preserved for diagnosis and is never dropped automatically."
echo "Restore phase started: schema, table data, indexes and constraints."
echo "Progress heartbeat interval: ${progress_interval} seconds. Exact percentage is unavailable because the source archive is compressed."
restore_started_at=$(date +%s)
monitor_restore "$$" "${restore_started_at}" &
restore_monitor_pid=$!
restore_status=0
target_restore || restore_status=$?
stop_restore_monitor
[ "${restore_status}" -eq 0 ] \
    || backup_fail "pg_restore failed with exit code ${restore_status}; run make data-restore-status before any retry"

restored_database=$(target_psql "${target_database}" 'SELECT current_database();' | tr -d '[:space:]')
[ "${restored_database}" = "${target_database}" ] \
    || backup_fail "restored database identity check failed"
restored_size=$(target_psql "${target_database}" 'SELECT pg_database_size(current_database());' | tr -d '[:space:]')
backup_validate_positive_integer restored_database_size "${restored_size}"

echo "Shadow restore completed: ${target_database}"
echo "Restored database size bytes: ${restored_size}"
echo "No application DSN, container, alias, volume, or source database was switched."
trap - EXIT HUP INT TERM
