#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)

environment_value() {
    environment_value_key=$1
    environment_value_file=$2

    awk -F= -v key="${environment_value_key}" '
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
    ' "${environment_value_file}"
}

if [ "$(id -u)" -eq 0 ] || [ -n "${SUDO_USER:-}" ]; then
    echo "Run development commands as the host user, without sudo." >&2
    exit 1
fi

env_file=${COMPOSE_ENV_FILE:-config/.env}

if [ ! -f "${project_root}/${env_file}" ] && [ "${env_file}" = "config/.env" ]; then
    env_file=config/.env.example
fi

case "${env_file}" in
    /*) ;;
    *) env_file="${project_root}/${env_file}" ;;
esac

if [ ! -f "${env_file}" ]; then
    echo "Compose environment file not found: ${env_file}" >&2
    exit 1
fi

postgres_profile=${POSTGRES_TUNING_PROFILE:-}
if [ -z "${postgres_profile}" ]; then
    postgres_profile=$(environment_value POSTGRES_TUNING_PROFILE "${env_file}" 2>/dev/null || true)
fi
postgres_profile=${postgres_profile:-dev}
case "${postgres_profile}" in
    dev|prod) ;;
    *)
        echo "POSTGRES_TUNING_PROFILE must be dev or prod." >&2
        exit 1
        ;;
esac

postgres_profile_file="${project_root}/config/postgres/${postgres_profile}.env"
if [ ! -f "${postgres_profile_file}" ]; then
    echo "PostgreSQL tuning profile not found: ${postgres_profile_file}" >&2
    exit 1
fi

check_postgres_capacity=0
for argument in "$@"; do
    case "${argument}" in
        up|start|restart) check_postgres_capacity=1 ;;
    esac
done

if [ "${check_postgres_capacity}" -eq 1 ]; then
    POSTGRES_CAPACITY_CHECK=1 \
        POSTGRES_TUNING_PROFILE="${postgres_profile}" \
        POSTGRES_PROFILE_FILE="${postgres_profile_file}" \
        COMPOSE_ENV_FILE="${env_file}" \
        "${project_root}/docker/scripts/postgres-tuning-check.sh"
fi

export HOST_UID="${HOST_UID:-$(id -u)}"
export HOST_GID="${HOST_GID:-$(id -g)}"

exec docker compose \
    --env-file "${postgres_profile_file}" \
    --env-file "${env_file}" \
    --file "${script_dir}/dev/compose.yaml" \
    "$@"
