#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)

# shellcheck disable=SC1091
. "${script_dir}/backup-lib.sh"

for command_name in awk date df docker git grep mktemp mv sleep tr wc; do
    backup_require_command "${command_name}"
done

if [ "$(id -u)" -eq 0 ] || [ -n "${SUDO_USER:-}" ]; then
    backup_fail "run as the deployment user, without sudo"
fi

component=${BACKUP_COMPONENT:-}
backup_validate_component "${component}"

backup_root=${BACKUP_ROOT:-}
backup_require_directory BACKUP_ROOT "${backup_root}"
[ -w "${backup_root}" ] || backup_fail "BACKUP_ROOT is not writable: ${backup_root}"

reserve_gb=${BACKUP_RESERVE_GB:-10}
backup_validate_positive_integer BACKUP_RESERVE_GB "${reserve_gb}"
progress_interval=${BACKUP_PROGRESS_INTERVAL_SECONDS:-30}
backup_validate_positive_integer BACKUP_PROGRESS_INTERVAL_SECONDS "${progress_interval}"

case "${component}" in
    application-postgres)
        service=postgres
        container=${BACKUP_CONTAINER:-$(backup_resolve_compose_container "${project_root}" "${service}")}
        backup_validate_container "${container}"
        database_name=${BACKUP_DATABASE_NAME:-$(backup_container_environment_value "${container}" POSTGRES_DB || true)}
        database_user=${BACKUP_DATABASE_USER:-$(backup_container_environment_value "${container}" POSTGRES_USER || true)}
        container_user=
        provenance_name=${BACKUP_PROVENANCE_NAME:-canonical-gar-database}
        provenance_sha256=${BACKUP_PROVENANCE_SHA256:-none}
        ;;
    nominatim)
        service=nominatim
        container=${BACKUP_CONTAINER:-$(backup_resolve_compose_container "${project_root}" "${service}")}
        backup_validate_container "${container}"
        database_name=${BACKUP_DATABASE_NAME:-nominatim}
        database_user=${BACKUP_DATABASE_USER:-nominatim}
        container_user=nominatim
        provenance_name=${BACKUP_PROVENANCE_NAME:-}
        provenance_sha256=${BACKUP_PROVENANCE_SHA256:-}
        [ -n "${provenance_name}" ] \
            || backup_fail "BACKUP_PROVENANCE_NAME must identify the Nominatim PBF or its manifest"
        backup_validate_sha256 BACKUP_PROVENANCE_SHA256 "${provenance_sha256}"
        ;;
esac

backup_validate_identifier BACKUP_DATABASE_NAME "${database_name}"
backup_validate_identifier BACKUP_DATABASE_USER "${database_user}"
case "${provenance_name}" in
    ''|*[!A-Za-z0-9._:/-]*)
        backup_fail "BACKUP_PROVENANCE_NAME contains unsupported characters"
        ;;
esac
if [ "${provenance_sha256}" != none ]; then
    backup_validate_sha256 BACKUP_PROVENANCE_SHA256 "${provenance_sha256}"
fi
application_revision=${BACKUP_APPLICATION_REVISION:-$(git -C "${project_root}" rev-parse HEAD)}
backup_validate_revision BACKUP_APPLICATION_REVISION "${application_revision}"
if [ "${BACKUP_DRY_RUN:-0}" != 1 ] \
    && [ -z "${BACKUP_APPLICATION_REVISION:-}" ] \
    && [ -n "$(git -C "${project_root}" status --porcelain)" ]; then
    backup_fail "worktree is dirty; commit it or set the exact BACKUP_APPLICATION_REVISION explicitly"
fi

database_query() {
    backup_query=$1

    if [ -n "${container_user}" ]; then
        docker exec --user "${container_user}" "${container}" \
            psql --no-psqlrc --tuples-only --no-align --set=ON_ERROR_STOP=1 \
            --username "${database_user}" --dbname "${database_name}" --command "${backup_query}"
        return
    fi

    docker exec "${container}" \
        psql --no-psqlrc --tuples-only --no-align --set=ON_ERROR_STOP=1 \
        --username "${database_user}" --dbname "${database_name}" --command "${backup_query}"
}

database_dump() {
    backup_dump_path=$1

    if [ -n "${container_user}" ]; then
        docker exec --user "${container_user}" "${container}" \
            pg_dump --no-password --username "${database_user}" --dbname "${database_name}" \
            --format=custom --compress=zstd:6 --no-owner --no-privileges \
            --serializable-deferrable --lock-wait-timeout=60s >"${backup_dump_path}"
        return
    fi

    docker exec "${container}" \
        pg_dump --no-password --username "${database_user}" --dbname "${database_name}" \
        --format=custom --compress=zstd:6 --no-owner --no-privileges \
        --serializable-deferrable --lock-wait-timeout=60s >"${backup_dump_path}"
}

database_restore_list() {
    backup_dump_path=$1

    if [ -n "${container_user}" ]; then
        docker exec --user "${container_user}" --interactive "${container}" \
            pg_restore --list <"${backup_dump_path}" >/dev/null
        return
    fi

    docker exec --interactive "${container}" pg_restore --list \
        <"${backup_dump_path}" >/dev/null
}

database_size_bytes=$(database_query 'SELECT pg_database_size(current_database());' | tr -d '[:space:]')
backup_validate_positive_integer database_size_bytes "${database_size_bytes}"
server_version=$(database_query 'SHOW server_version;' | tr -d '[:space:]')
server_version_num=$(database_query 'SHOW server_version_num;' | tr -d '[:space:]')
backup_validate_positive_integer server_version_num "${server_version_num}"
pg_dump_version=$(
    if [ -n "${container_user}" ]; then
        docker exec --user "${container_user}" "${container}" pg_dump --version
    else
        docker exec "${container}" pg_dump --version
    fi
)
pg_dump_version=$(printf '%s\n' "${pg_dump_version}" | awk '{print $3}')
container_image=$(docker inspect --format '{{.Config.Image}}' "${container}")
container_id=$(docker inspect --format '{{.Id}}' "${container}")

available_kb=$(df -Pk "${backup_root}" | awk 'END {print $4}')
backup_validate_positive_integer available_kb "${available_kb}"
available_bytes=$((available_kb * 1024))
reserve_bytes=$((reserve_gb * 1024 * 1024 * 1024))
required_bytes=$((database_size_bytes + reserve_bytes))
[ "${available_bytes}" -ge "${required_bytes}" ] \
    || backup_fail "BACKUP_ROOT needs at least the source database size plus ${reserve_gb} GiB reserve"

echo "Backup preflight passed."
echo "Component: ${component}"
echo "Database size bytes: ${database_size_bytes}"
echo "PostgreSQL version: ${server_version}"
echo "Backup filesystem free bytes: ${available_bytes}"
echo "Required free bytes: ${required_bytes}"

if [ "${BACKUP_DRY_RUN:-0}" = 1 ]; then
    echo "Dry-run leaves the backup filesystem and database unchanged."
    exit 0
fi

created_at=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
timestamp=$(date -u '+%Y%m%dT%H%M%SZ')
final_directory="${backup_root}/${component}-${timestamp}"
[ ! -e "${final_directory}" ] || backup_fail "backup destination already exists: ${final_directory}"

staging_directory=$(mktemp -d "${backup_root}/.geo-registry-${component}.XXXXXX")
backup_monitor_pid=
stop_backup_monitor() {
    [ -n "${backup_monitor_pid}" ] || return 0
    kill "${backup_monitor_pid}" >/dev/null 2>&1 || true
    wait "${backup_monitor_pid}" >/dev/null 2>&1 || true
    backup_monitor_pid=
}
cleanup() {
    stop_backup_monitor
    case "${staging_directory}" in
        "${backup_root}"/.geo-registry-*) rm -rf -- "${staging_directory}" ;;
    esac
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM
chmod 0700 "${staging_directory}"

dump_file="${component}.dump"
dump_path="${staging_directory}/${dump_file}"
monitor_backup() {
    monitor_parent_pid=$1
    monitor_started_at=$2

    while kill -0 "${monitor_parent_pid}" >/dev/null 2>&1; do
        sleep "${progress_interval}"
        kill -0 "${monitor_parent_pid}" >/dev/null 2>&1 || break

        monitor_now=$(date +%s)
        monitor_elapsed=$((monitor_now - monitor_started_at))
        monitor_bytes=$(wc -c <"${dump_path}" 2>/dev/null | tr -d ' ' || true)
        [ -n "${monitor_bytes}" ] || monitor_bytes=0
        monitor_activity=$(database_query "
SELECT 'state=' || state
    || ' wait=' || COALESCE(wait_event_type || '/' || wait_event, 'cpu')
    || ' current_seconds=' || EXTRACT(epoch FROM (clock_timestamp() - query_start))::bigint
FROM pg_stat_activity
WHERE datname = '${database_name}'
  AND application_name = 'pg_dump'
ORDER BY query_start
LIMIT 1;
" 2>/dev/null || true)
        [ -n "${monitor_activity}" ] || monitor_activity='state=streaming-or-finishing'
        printf 'Backup progress: elapsed_seconds=%s archive_bytes=%s %s\n' \
            "${monitor_elapsed}" "${monitor_bytes}" "${monitor_activity}"
    done
}

echo "Creating transaction-consistent ${component} dump..."
echo "Progress heartbeat interval: ${progress_interval} seconds. Exact percentage is unavailable because the archive is compressed."
backup_started_at=$(date +%s)
monitor_backup "$$" "${backup_started_at}" &
backup_monitor_pid=$!
dump_status=0
database_dump "${dump_path}" || dump_status=$?
stop_backup_monitor
[ "${dump_status}" -eq 0 ] \
    || backup_fail "pg_dump failed with exit code ${dump_status}; no incomplete backup will be published"
chmod 0600 "${dump_path}"
echo "Dump stream completed; verifying pg_restore table of contents and calculating SHA-256."
database_restore_list "${dump_path}"

dump_bytes=$(backup_file_bytes "${dump_path}")
backup_validate_positive_integer dump_bytes "${dump_bytes}"
dump_sha256=$(backup_sha256 "${dump_path}")

cat >"${staging_directory}/manifest.txt" <<EOF
format=geo-registry-postgres-backup-v1
created_at=${created_at}
component=${component}
application_revision=${application_revision}
container_image=${container_image}
container_id=${container_id}
database_name=${database_name}
database_user=${database_user}
database_size_bytes=${database_size_bytes}
server_version=${server_version}
server_version_num=${server_version_num}
pg_dump_version=${pg_dump_version}
dump_format=custom
dump_compression=zstd:6
dump_file=${dump_file}
dump_bytes=${dump_bytes}
dump_sha256=${dump_sha256}
provenance_name=${provenance_name}
provenance_sha256=${provenance_sha256}
EOF
chmod 0600 "${staging_directory}/manifest.txt"

mv "${staging_directory}" "${final_directory}"
trap - EXIT HUP INT TERM

echo "Backup created: ${final_directory}"
echo "Dump SHA-256: ${dump_sha256}"
