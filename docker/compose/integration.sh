#!/bin/sh
set -eu

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)

if [ "$(id -u)" -eq 0 ] || [ -n "${SUDO_USER:-}" ]; then
    echo "Run integration commands as the host user, without sudo." >&2
    exit 1
fi

project_name=${INTEGRATION_PROJECT_NAME:-geo-registry-integration}

exec docker compose \
    --project-name "${project_name}" \
    --file "${script_dir}/integration/compose.yaml" \
    --profile integration \
    "$@"
