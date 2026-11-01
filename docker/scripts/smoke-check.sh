#!/bin/sh
set -eu

base_url="${APP_BASE_URL:-http://127.0.0.1:8080}"

curl --fail --silent --show-error "${base_url}/health/live" >/dev/null
curl --fail --silent --show-error "${base_url}/health/ready" >/dev/null

echo "Smoke checks passed: ${base_url}"
