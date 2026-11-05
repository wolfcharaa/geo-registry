#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)

fail() {
    echo "PostgreSQL tuning check failed: $*" >&2
    exit 1
}

warn() {
    echo "PostgreSQL tuning warning: $*" >&2
}

file_value() {
    file_value_key=$1
    file_value_path=$2

    awk -F= -v key="${file_value_key}" '
        $1 == key {
            value = substr($0, index($0, "=") + 1)
            sub(/\r$/, "", value)
            found = 1
        }
        END {
            if (!found) {
                exit 1
            }
            print value
        }
    ' "${file_value_path}"
}

effective_value() {
    effective_value_key=$1
    effective_value_present=
    eval "effective_value_present=\${${effective_value_key}+x}"
    if [ "${effective_value_present}" = x ]; then
        eval "effective_value_result=\${${effective_value_key}}"
        printf '%s\n' "${effective_value_result}"
        return
    fi

    if effective_value_result=$(file_value "${effective_value_key}" "${override_file}" 2>/dev/null); then
        printf '%s\n' "${effective_value_result}"
        return
    fi

    file_value "${effective_value_key}" "${profile_file}" 2>/dev/null \
        || fail "${effective_value_key} is missing from ${profile_file}"
}

require_positive_integer() {
    require_integer_name=$1
    require_integer_value=$2
    case "${require_integer_value}" in
        ''|*[!0-9]*) fail "${require_integer_name} must be a positive integer" ;;
    esac
    [ "${require_integer_value}" -gt 0 ] \
        || fail "${require_integer_name} must be greater than zero"
}

require_non_negative_integer() {
    require_integer_name=$1
    require_integer_value=$2
    case "${require_integer_value}" in
        ''|*[!0-9]*) fail "${require_integer_name} must be a non-negative integer" ;;
    esac
}

require_one_of() {
    require_one_of_name=$1
    require_one_of_value=$2
    shift 2
    for require_one_of_allowed in "$@"; do
        [ "${require_one_of_value}" = "${require_one_of_allowed}" ] && return
    done
    fail "${require_one_of_name} has unsupported value: ${require_one_of_value}"
}

size_bytes() {
    size_value=$1
    awk -v value="${size_value}" 'BEGIN {
        gsub(/[[:space:]]/, "", value)
        if (value !~ /^[0-9]+([kKmMgGtT]([bB])?|[bB])$/) {
            exit 1
        }

        amount = value
        sub(/[[:alpha:]]+$/, "", amount)
        unit = value
        sub(/^[0-9]+/, "", unit)
        unit = tolower(unit)

        factor = 1
        if (unit == "k" || unit == "kb") factor = 1024
        if (unit == "m" || unit == "mb") factor = 1024 * 1024
        if (unit == "g" || unit == "gb") factor = 1024 * 1024 * 1024
        if (unit == "t" || unit == "tb") factor = 1024 * 1024 * 1024 * 1024

        printf "%.0f\n", amount * factor
    }'
}

time_seconds() {
    time_value=$1
    awk -v value="${time_value}" 'BEGIN {
        gsub(/[[:space:]]/, "", value)
        if (value !~ /^[0-9]+(ms|s|min|h|d)$/) {
            exit 1
        }

        amount = value
        sub(/[[:alpha:]]+$/, "", amount)
        unit = value
        sub(/^[0-9]+/, "", unit)

        factor = 1
        if (unit == "ms") factor = 0.001
        if (unit == "min") factor = 60
        if (unit == "h") factor = 3600
        if (unit == "d") factor = 86400

        printf "%.3f\n", amount * factor
    }'
}

override_file=${COMPOSE_ENV_FILE:-config/.env}
case "${override_file}" in
    /*) ;;
    *) override_file="${project_root}/${override_file}" ;;
esac
if [ ! -f "${override_file}" ] && [ "${override_file}" = "${project_root}/config/.env" ]; then
    override_file="${project_root}/config/.env.example"
fi
[ -r "${override_file}" ] || fail "override file is not readable: ${override_file}"

profile=${POSTGRES_TUNING_PROFILE:-}
if [ -z "${profile}" ]; then
    profile=$(file_value POSTGRES_TUNING_PROFILE "${override_file}" 2>/dev/null || true)
fi
profile=${profile:-dev}
case "${profile}" in
    dev|prod) ;;
    *) fail "POSTGRES_TUNING_PROFILE must be dev or prod" ;;
esac

profile_file=${POSTGRES_PROFILE_FILE:-${project_root}/config/postgres/${profile}.env}
case "${profile_file}" in
    /*) ;;
    *) profile_file="${project_root}/${profile_file}" ;;
esac
[ -r "${profile_file}" ] || fail "profile file is not readable: ${profile_file}"

min_cpu=$(effective_value POSTGRES_MIN_CPU)
recommended_cpu=$(effective_value POSTGRES_RECOMMENDED_CPU)
min_memory_gb=$(effective_value POSTGRES_MIN_MEMORY_GB)
recommended_memory_gb=$(effective_value POSTGRES_RECOMMENDED_MEMORY_GB)
min_free_disk_gb=$(effective_value POSTGRES_MIN_FREE_DISK_GB)
recommended_free_disk_gb=$(effective_value POSTGRES_RECOMMENDED_FREE_DISK_GB)

max_connections=$(effective_value POSTGRES_MAX_CONNECTIONS)
shared_buffers=$(effective_value POSTGRES_SHARED_BUFFERS)
effective_cache_size=$(effective_value POSTGRES_EFFECTIVE_CACHE_SIZE)
work_mem=$(effective_value POSTGRES_WORK_MEM)
maintenance_work_mem=$(effective_value POSTGRES_MAINTENANCE_WORK_MEM)
autovacuum_work_mem=$(effective_value POSTGRES_AUTOVACUUM_WORK_MEM)
autovacuum_max_workers=$(effective_value POSTGRES_AUTOVACUUM_MAX_WORKERS)
wal_buffers=$(effective_value POSTGRES_WAL_BUFFERS)
min_wal_size=$(effective_value POSTGRES_MIN_WAL_SIZE)
max_wal_size=$(effective_value POSTGRES_MAX_WAL_SIZE)
wal_compression=$(effective_value POSTGRES_WAL_COMPRESSION)
checkpoint_timeout=$(effective_value POSTGRES_CHECKPOINT_TIMEOUT)
checkpoint_completion_target=$(effective_value POSTGRES_CHECKPOINT_COMPLETION_TARGET)
checkpoint_warning=$(effective_value POSTGRES_CHECKPOINT_WARNING)
max_worker_processes=$(effective_value POSTGRES_MAX_WORKER_PROCESSES)
max_parallel_workers=$(effective_value POSTGRES_MAX_PARALLEL_WORKERS)
max_parallel_workers_per_gather=$(effective_value POSTGRES_MAX_PARALLEL_WORKERS_PER_GATHER)
max_parallel_maintenance_workers=$(effective_value POSTGRES_MAX_PARALLEL_MAINTENANCE_WORKERS)
effective_io_concurrency=$(effective_value POSTGRES_EFFECTIVE_IO_CONCURRENCY)
maintenance_io_concurrency=$(effective_value POSTGRES_MAINTENANCE_IO_CONCURRENCY)
huge_pages=$(effective_value POSTGRES_HUGE_PAGES)
track_io_timing=$(effective_value POSTGRES_TRACK_IO_TIMING)
track_wal_io_timing=$(effective_value POSTGRES_TRACK_WAL_IO_TIMING)
shm_size=$(effective_value POSTGRES_SHM_SIZE)

for requirement in \
    "POSTGRES_MIN_CPU:${min_cpu}" \
    "POSTGRES_RECOMMENDED_CPU:${recommended_cpu}" \
    "POSTGRES_MIN_MEMORY_GB:${min_memory_gb}" \
    "POSTGRES_RECOMMENDED_MEMORY_GB:${recommended_memory_gb}" \
    "POSTGRES_MIN_FREE_DISK_GB:${min_free_disk_gb}" \
    "POSTGRES_RECOMMENDED_FREE_DISK_GB:${recommended_free_disk_gb}" \
    "POSTGRES_MAX_CONNECTIONS:${max_connections}" \
    "POSTGRES_AUTOVACUUM_MAX_WORKERS:${autovacuum_max_workers}" \
    "POSTGRES_MAX_WORKER_PROCESSES:${max_worker_processes}"
do
    require_positive_integer "${requirement%%:*}" "${requirement#*:}"
done

for setting in \
    "POSTGRES_MAX_PARALLEL_WORKERS:${max_parallel_workers}" \
    "POSTGRES_MAX_PARALLEL_WORKERS_PER_GATHER:${max_parallel_workers_per_gather}" \
    "POSTGRES_MAX_PARALLEL_MAINTENANCE_WORKERS:${max_parallel_maintenance_workers}" \
    "POSTGRES_EFFECTIVE_IO_CONCURRENCY:${effective_io_concurrency}" \
    "POSTGRES_MAINTENANCE_IO_CONCURRENCY:${maintenance_io_concurrency}"
do
    require_non_negative_integer "${setting%%:*}" "${setting#*:}"
done

[ "${recommended_cpu}" -ge "${min_cpu}" ] \
    || fail "recommended CPU must not be below minimum CPU"
[ "${recommended_memory_gb}" -ge "${min_memory_gb}" ] \
    || fail "recommended memory must not be below minimum memory"
[ "${recommended_free_disk_gb}" -ge "${min_free_disk_gb}" ] \
    || fail "recommended free disk must not be below minimum free disk"

shared_buffers_bytes=$(size_bytes "${shared_buffers}") \
    || fail "POSTGRES_SHARED_BUFFERS must be an integer with B, kB, MB, or GB unit"
effective_cache_size_bytes=$(size_bytes "${effective_cache_size}") \
    || fail "POSTGRES_EFFECTIVE_CACHE_SIZE must be an integer size"
work_mem_bytes=$(size_bytes "${work_mem}") \
    || fail "POSTGRES_WORK_MEM must be an integer size"
maintenance_work_mem_bytes=$(size_bytes "${maintenance_work_mem}") \
    || fail "POSTGRES_MAINTENANCE_WORK_MEM must be an integer size"
autovacuum_work_mem_bytes=$(size_bytes "${autovacuum_work_mem}") \
    || fail "POSTGRES_AUTOVACUUM_WORK_MEM must be an integer size"
wal_buffers_bytes=$(size_bytes "${wal_buffers}") \
    || fail "POSTGRES_WAL_BUFFERS must be an integer size"
min_wal_size_bytes=$(size_bytes "${min_wal_size}") \
    || fail "POSTGRES_MIN_WAL_SIZE must be an integer size"
max_wal_size_bytes=$(size_bytes "${max_wal_size}") \
    || fail "POSTGRES_MAX_WAL_SIZE must be an integer size"
shm_size_bytes=$(size_bytes "${shm_size}") \
    || fail "POSTGRES_SHM_SIZE must be an integer size"

checkpoint_timeout_seconds=$(time_seconds "${checkpoint_timeout}") \
    || fail "POSTGRES_CHECKPOINT_TIMEOUT must use ms, s, min, h, or d"
checkpoint_warning_seconds=$(time_seconds "${checkpoint_warning}") \
    || fail "POSTGRES_CHECKPOINT_WARNING must use ms, s, min, h, or d"

[ "${shared_buffers_bytes}" -ge 131072 ] \
    || fail "POSTGRES_SHARED_BUFFERS must be at least 128kB"
[ "${effective_cache_size_bytes}" -ge 8192 ] \
    || fail "POSTGRES_EFFECTIVE_CACHE_SIZE must be at least 8kB"
[ "${work_mem_bytes}" -ge 65536 ] \
    || fail "POSTGRES_WORK_MEM must be at least 64kB"
[ "${maintenance_work_mem_bytes}" -ge 1048576 ] \
    || fail "POSTGRES_MAINTENANCE_WORK_MEM must be at least 1MB"
[ "${autovacuum_work_mem_bytes}" -ge 1048576 ] \
    || fail "POSTGRES_AUTOVACUUM_WORK_MEM must be at least 1MB"
[ "${wal_buffers_bytes}" -ge 32768 ] \
    || fail "POSTGRES_WAL_BUFFERS must be at least 32kB"
[ "${min_wal_size_bytes}" -ge 33554432 ] \
    || fail "POSTGRES_MIN_WAL_SIZE must be at least 32MB"

require_one_of POSTGRES_WAL_COMPRESSION "${wal_compression}" on off pglz lz4 zstd
require_one_of POSTGRES_HUGE_PAGES "${huge_pages}" try on off
require_one_of POSTGRES_TRACK_IO_TIMING "${track_io_timing}" on off
require_one_of POSTGRES_TRACK_WAL_IO_TIMING "${track_wal_io_timing}" on off

awk -v value="${checkpoint_completion_target}" \
    'BEGIN { exit !(value ~ /^0([.][0-9]+)?$/ && value > 0 && value < 1) }' \
    || fail "POSTGRES_CHECKPOINT_COMPLETION_TARGET must be greater than 0 and below 1"
awk -v warning="${checkpoint_warning_seconds}" -v timeout="${checkpoint_timeout_seconds}" \
    'BEGIN { exit !(warning <= timeout) }' \
    || fail "checkpoint warning must not exceed checkpoint timeout"
awk -v timeout="${checkpoint_timeout_seconds}" \
    'BEGIN { exit !(timeout >= 30 && timeout <= 86400) }' \
    || fail "checkpoint timeout must be between 30 seconds and 1 day"

[ "${effective_io_concurrency}" -le 1000 ] \
    || fail "POSTGRES_EFFECTIVE_IO_CONCURRENCY must not exceed 1000"
[ "${maintenance_io_concurrency}" -le 1000 ] \
    || fail "POSTGRES_MAINTENANCE_IO_CONCURRENCY must not exceed 1000"

[ "${min_wal_size_bytes}" -le "${max_wal_size_bytes}" ] \
    || fail "POSTGRES_MIN_WAL_SIZE must not exceed POSTGRES_MAX_WAL_SIZE"
[ "${max_parallel_workers}" -le "${max_worker_processes}" ] \
    || fail "parallel workers must not exceed worker processes"
[ "${max_parallel_workers_per_gather}" -le "${max_parallel_workers}" ] \
    || fail "parallel workers per gather must not exceed parallel workers"
[ "${max_parallel_maintenance_workers}" -le "${max_parallel_workers}" ] \
    || fail "parallel maintenance workers must not exceed parallel workers"
[ "${shm_size_bytes}" -ge "${shared_buffers_bytes}" ] \
    || fail "POSTGRES_SHM_SIZE must not be below POSTGRES_SHARED_BUFFERS"

min_memory_bytes=$((min_memory_gb * 1024 * 1024 * 1024))
min_free_disk_bytes=$((min_free_disk_gb * 1024 * 1024 * 1024))
declared_memory_bytes=$((
    shared_buffers_bytes
    + work_mem_bytes * max_connections
    + maintenance_work_mem_bytes
    + autovacuum_work_mem_bytes * autovacuum_max_workers
    + wal_buffers_bytes
))

awk -v shared="${shared_buffers_bytes}" -v memory="${min_memory_bytes}" \
    'BEGIN { exit !(shared <= memory * 0.40) }' \
    || fail "shared buffers exceed 40% of the declared minimum memory"
[ "${effective_cache_size_bytes}" -le "${min_memory_bytes}" ] \
    || fail "effective cache size exceeds the declared minimum memory"
awk -v declared="${declared_memory_bytes}" -v memory="${min_memory_bytes}" \
    'BEGIN { exit !(declared <= memory * 0.75) }' \
    || fail "baseline PostgreSQL memory estimate exceeds 75% of minimum memory"
[ "${min_free_disk_bytes}" -ge "$((max_wal_size_bytes * 2))" ] \
    || fail "minimum free disk must be at least twice POSTGRES_MAX_WAL_SIZE"

if [ -n "${SERVER_MIN_MEMORY_GB:-}" ]; then
    require_positive_integer SERVER_MIN_MEMORY_GB "${SERVER_MIN_MEMORY_GB}"
    [ "${SERVER_MIN_MEMORY_GB}" -ge "${min_memory_gb}" ] \
        || fail "approved server memory budget is below the ${profile} profile minimum"
fi
if [ -n "${SERVER_MIN_FREE_GB:-}" ]; then
    require_positive_integer SERVER_MIN_FREE_GB "${SERVER_MIN_FREE_GB}"
    [ "${SERVER_MIN_FREE_GB}" -ge "${min_free_disk_gb}" ] \
        || fail "approved server disk budget is below the ${profile} profile minimum"
fi

capacity_check=${POSTGRES_CAPACITY_CHECK:-1}
require_one_of POSTGRES_CAPACITY_CHECK "${capacity_check}" 1 0 on off true false yes no
case "${capacity_check}" in
    0|off|false|no)
        echo "PostgreSQL ${profile} profile is internally consistent."
        echo "Required: ${min_cpu} CPU, ${min_memory_gb} GiB RAM, ${min_free_disk_gb} GiB free disk."
        exit 0
        ;;
esac

command -v docker >/dev/null 2>&1 || fail "docker command is unavailable"
command -v df >/dev/null 2>&1 || fail "df command is unavailable"

docker_os=$(docker info --format '{{.OSType}}' 2>/dev/null) \
    || fail "Docker daemon is unavailable"
[ "${docker_os}" = linux ] || fail "Docker daemon must run Linux containers"
docker_cpu=$(docker info --format '{{.NCPU}}' 2>/dev/null) \
    || fail "cannot determine Docker CPU capacity"
docker_memory_bytes=$(docker info --format '{{.MemTotal}}' 2>/dev/null) \
    || fail "cannot determine Docker memory capacity"
docker_root=$(docker info --format '{{.DockerRootDir}}' 2>/dev/null) \
    || fail "cannot determine Docker storage root"

require_positive_integer docker_cpu "${docker_cpu}"
require_positive_integer docker_memory_bytes "${docker_memory_bytes}"
[ "${docker_cpu}" -ge "${min_cpu}" ] \
    || fail "Docker exposes ${docker_cpu} CPU; ${profile} requires at least ${min_cpu}"
[ "${docker_memory_bytes}" -ge "${min_memory_bytes}" ] \
    || fail "Docker memory is below the ${profile} minimum of ${min_memory_gb} GiB"

storage_path=${SERVER_STORAGE_PATH:-${docker_root}}
case "${storage_path}" in
    /*) ;;
    *) fail "capacity storage path must be absolute" ;;
esac
[ -d "${storage_path}" ] || fail "capacity storage path does not exist: ${storage_path}"
available_kb=$(df -Pk "${storage_path}" | awk 'NR == 2 {print $4}')
require_positive_integer available_disk_kb "${available_kb}"
min_free_disk_kb=$((min_free_disk_gb * 1024 * 1024))
[ "${available_kb}" -ge "${min_free_disk_kb}" ] \
    || fail "free disk is below the ${profile} minimum of ${min_free_disk_gb} GiB"

recommended_memory_bytes=$((recommended_memory_gb * 1024 * 1024 * 1024))
recommended_free_disk_kb=$((recommended_free_disk_gb * 1024 * 1024))
[ "${docker_cpu}" -ge "${recommended_cpu}" ] \
    || warn "${profile} recommends ${recommended_cpu} CPU; Docker exposes ${docker_cpu}"
[ "${docker_memory_bytes}" -ge "${recommended_memory_bytes}" ] \
    || warn "${profile} recommends ${recommended_memory_gb} GiB RAM"
[ "${available_kb}" -ge "${recommended_free_disk_kb}" ] \
    || warn "${profile} recommends ${recommended_free_disk_gb} GiB free disk"

docker_memory_gib=$(awk -v bytes="${docker_memory_bytes}" 'BEGIN {printf "%.1f", bytes / 1024 / 1024 / 1024}')
available_disk_gib=$(awk -v kib="${available_kb}" 'BEGIN {printf "%.1f", kib / 1024 / 1024}')

echo "PostgreSQL ${profile} profile and host capacity passed."
echo "Required/recommended: CPU ${min_cpu}/${recommended_cpu}, RAM ${min_memory_gb}/${recommended_memory_gb} GiB, free disk ${min_free_disk_gb}/${recommended_free_disk_gb} GiB."
echo "Detected: CPU ${docker_cpu}, RAM ${docker_memory_gib} GiB, free disk ${available_disk_gib} GiB."
echo "Baseline PostgreSQL memory estimate: ${declared_memory_bytes} bytes."
echo "Capacity storage path: ${storage_path}."
