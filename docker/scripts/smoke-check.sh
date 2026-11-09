#!/bin/sh
set -eu

base_url="${APP_BASE_URL:-http://127.0.0.1:8080}"
connect_timeout=${SMOKE_CONNECT_TIMEOUT_SECONDS:-3}
request_timeout=${SMOKE_REQUEST_TIMEOUT_SECONDS:-10}

for timeout_value in "${connect_timeout}" "${request_timeout}"; do
    case "${timeout_value}" in
        ''|*[!0-9]*)
            echo 'Smoke check error: timeouts must be positive integer seconds.' >&2
            exit 1
            ;;
    esac
    if [ "${timeout_value}" -le 0 ]; then
        echo 'Smoke check error: timeouts must be positive integer seconds.' >&2
        exit 1
    fi
done

for health_path in /health/live /health/startup /health/ready; do
    curl --fail --silent --show-error \
        --connect-timeout "${connect_timeout}" \
        --max-time "${request_timeout}" \
        --output /dev/null \
        "${base_url}${health_path}"
done

echo "Smoke checks passed: ${base_url}"
