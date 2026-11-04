#!/bin/sh
set -eu

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH= cd -- "${script_dir}/../.." && pwd)
workspace_root=$(CDPATH= cd -- "${project_root}/.." && pwd)
core_root="${workspace_root}/library/MessageBusAsync"
worker_root="${workspace_root}/library/MessageBusAsyncWorker"
lock_file="${project_root}/app/composer.lock"

fail() {
    echo "Server preflight failed: $*" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "required command is missing: $1"
}

require_positive_integer() {
    name=$1
    value=$2

    case "${value}" in
        ''|*[!0-9]*) fail "${name} must be an explicit positive integer" ;;
    esac
    [ "${value}" -gt 0 ] || fail "${name} must be greater than zero"
}

require_clean_repository() {
    repository=$1
    label=$2

    git -C "${repository}" rev-parse --is-inside-work-tree >/dev/null 2>&1 \
        || fail "${label} repository is unavailable: ${repository}"
    [ -z "$(git -C "${repository}" status --porcelain)" ] \
        || fail "${label} repository has uncommitted changes"
}

require_environment_value() {
    key=$1

    grep -Eq "^${key}=.+$" "${env_file}" \
        || fail "${key} must be set explicitly in ${env_file}"
}

locked_reference() {
    package_name=$1

    XDEBUG_MODE=off php -r '
        $lock = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
        foreach (array_merge($lock["packages"] ?? [], $lock["packages-dev"] ?? []) as $package) {
            if (($package["name"] ?? null) === $argv[2]) {
                echo $package["dist"]["reference"] ?? $package["source"]["reference"] ?? "";
                exit(0);
            }
        }
        exit(1);
    ' "${lock_file}" "${package_name}"
}

for command_name in docker git php awk df grep; do
    require_command "${command_name}"
done

if [ "$(id -u)" -eq 0 ] || [ -n "${SUDO_USER:-}" ]; then
    fail "run as the deployment user, without sudo"
fi

minimum_free_gb=${SERVER_MIN_FREE_GB:-}
minimum_memory_gb=${SERVER_MIN_MEMORY_GB:-}
require_positive_integer SERVER_MIN_FREE_GB "${minimum_free_gb}"
require_positive_integer SERVER_MIN_MEMORY_GB "${minimum_memory_gb}"

gar_source=${GAR_SOURCE:-}
[ -n "${gar_source}" ] || fail "GAR_SOURCE must point to the approved XML file or directory"
case "${gar_source}" in
    /*) ;;
    *) fail "GAR_SOURCE must be absolute" ;;
esac
[ -e "${gar_source}" ] || fail "GAR source does not exist: ${gar_source}"
[ -r "${gar_source}" ] || fail "GAR source is not readable: ${gar_source}"

storage_path=${SERVER_STORAGE_PATH:-${project_root}}
case "${storage_path}" in
    /*) ;;
    *) fail "SERVER_STORAGE_PATH must be absolute" ;;
esac
[ -d "${storage_path}" ] || fail "storage path does not exist: ${storage_path}"
[ -w "${storage_path}" ] || fail "storage path is not writable: ${storage_path}"

env_file=${COMPOSE_ENV_FILE:-config/.env}
case "${env_file}" in
    /*) ;;
    *) env_file="${project_root}/${env_file}" ;;
esac
[ -f "${env_file}" ] || fail "Compose environment file does not exist: ${env_file}"
[ -r "${env_file}" ] || fail "Compose environment file is not readable: ${env_file}"

for environment_key in \
    DATABASE_DSN \
    POSTGRES_PASSWORD \
    NOMINATIM_PASSWORD \
    NOMINATIM_PBF_URL \
    NOMINATIM_REPLICATION_URL
do
    require_environment_value "${environment_key}"
done

if grep -Eq 'local-app-only|local-nominatim-only' "${env_file}"; then
    fail "development-only database credentials remain in ${env_file}"
fi
if grep -Eq 'monaco-latest\.osm\.pbf|europe/monaco-updates' "${env_file}"; then
    fail "development Monaco Nominatim dataset remains in ${env_file}"
fi

require_clean_repository "${project_root}" "geo-registry"
require_clean_repository "${core_root}" "message-bus-async"
require_clean_repository "${worker_root}" "message-bus-async-worker"

core_commit=$(git -C "${core_root}" rev-parse HEAD)
worker_commit=$(git -C "${worker_root}" rev-parse HEAD)
core_locked_reference=$(locked_reference "romanfedorskij/message-bus-async")
worker_locked_reference=$(locked_reference "romanfedorskij/message-bus-async-worker")
[ "${core_locked_reference}" = "${core_commit}" ] \
    || fail "Composer lock does not match message-bus-async HEAD"
[ "${worker_locked_reference}" = "${worker_commit}" ] \
    || fail "Composer lock does not match message-bus-async-worker HEAD"

docker compose version >/dev/null 2>&1 || fail "Docker Compose v2 is unavailable"
docker_info=$(docker info --format '{{.OSType}} {{.MemTotal}}' 2>/dev/null) \
    || fail "Docker daemon is unavailable"
docker_os=$(printf '%s\n' "${docker_info}" | awk '{print $1}')
docker_memory_bytes=$(printf '%s\n' "${docker_info}" | awk '{print $2}')
[ "${docker_os}" = "linux" ] || fail "Docker daemon must run Linux containers"
case "${docker_memory_bytes}" in
    ''|*[!0-9]*) fail "Docker daemon returned an invalid memory limit" ;;
esac

minimum_memory_bytes=$((minimum_memory_gb * 1024 * 1024 * 1024))
[ "${docker_memory_bytes}" -ge "${minimum_memory_bytes}" ] \
    || fail "Docker memory is below the approved ${minimum_memory_gb} GiB budget"

available_kb=$(df -Pk "${storage_path}" | awk 'NR == 2 {print $4}')
case "${available_kb}" in
    ''|*[!0-9]*) fail "cannot determine free space for ${storage_path}" ;;
esac
minimum_free_kb=$((minimum_free_gb * 1024 * 1024))
[ "${available_kb}" -ge "${minimum_free_kb}" ] \
    || fail "free space is below the approved ${minimum_free_gb} GiB budget"

(
    cd "${project_root}"
    COMPOSE_ENV_FILE="${env_file}" docker/compose/dev.sh config --quiet
) || fail "resolved Compose configuration is invalid"

echo "Server preflight passed without changing runtime state."
echo "Application: $(git -C "${project_root}" rev-parse HEAD)"
echo "MessageBus core: ${core_commit}"
echo "MessageBus worker: ${worker_commit}"
echo "Docker platform: ${docker_os}"
echo "Docker memory bytes: ${docker_memory_bytes}"
echo "Storage path: ${storage_path}"
echo "Storage free KiB: ${available_kb}"
echo "GAR source: ${gar_source}"
