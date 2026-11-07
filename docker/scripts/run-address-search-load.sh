#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)

if [ "$(id -u)" -eq 0 ] || [ -n "${SUDO_USER:-}" ]; then
    echo "Run load tests as the host user, without sudo." >&2
    exit 1
fi

k6_image=${K6_IMAGE:-grafana/k6:2.3.0@sha256:2dd4756ec6e14e62778916e1eb850108e4b499304c02df56760fa0bdb0fba0b8}
base_url=${ADDRESS_LOAD_BASE_URL:-http://host.docker.internal:8080}

case "${base_url}" in
    http://*|https://*) ;;
    *)
        echo "ADDRESS_LOAD_BASE_URL must be an absolute HTTP(S) URL." >&2
        exit 1
        ;;
esac

exec docker run --rm \
    --add-host host.docker.internal:host-gateway \
    --env ADDRESS_LOAD_BASE_URL="${base_url}" \
    --env ADDRESS_LOAD_DURATION="${ADDRESS_LOAD_DURATION:-30s}" \
    --env ADDRESS_LOAD_MAX_P95_MS="${ADDRESS_LOAD_MAX_P95_MS:-750}" \
    --env ADDRESS_LOAD_MAX_P99_MS="${ADDRESS_LOAD_MAX_P99_MS:-1500}" \
    --env ADDRESS_LOAD_TIMEOUT="${ADDRESS_LOAD_TIMEOUT:-10s}" \
    --env ADDRESS_LOAD_VARIANT="${ADDRESS_LOAD_VARIANT:-unspecified}" \
    --env ADDRESS_LOAD_VUS="${ADDRESS_LOAD_VUS:-16}" \
    --volume "${project_root}/tests/load:/load:ro" \
    "${k6_image}" run /load/address-search.js
