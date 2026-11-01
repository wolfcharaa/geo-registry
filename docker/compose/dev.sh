#!/bin/sh
set -eu

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH= cd -- "${script_dir}/../.." && pwd)

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

export HOST_UID=${HOST_UID:-$(id -u)}
export HOST_GID=${HOST_GID:-$(id -g)}

exec docker compose \
    --env-file "${env_file}" \
    --file "${script_dir}/dev/compose.yaml" \
    "$@"
