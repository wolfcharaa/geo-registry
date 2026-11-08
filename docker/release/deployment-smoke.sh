#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)

# shellcheck disable=SC1091
. "${script_dir}/release-lib.sh"

case "${DEPLOY_SMOKE_DRY_RUN:-0}" in
    0|1) ;;
    *) release_fail "DEPLOY_SMOKE_DRY_RUN must be 0 or 1" ;;
esac

binding=$("${script_dir}/deployment-http-binding.sh")
IFS='|' read -r host_ip published_port target_port <<EOF
${binding}
EOF
case "${host_ip}" in
    0.0.0.0) request_host=127.0.0.1 ;;
    ::) request_host='[::1]' ;;
    *:*) request_host="[${host_ip}]" ;;
    *) request_host=${host_ip} ;;
esac
base_url="http://${request_host}:${published_port}"

echo "Deployment HTTP smoke target: ${base_url} (container port ${target_port})"
if [ "${DEPLOY_SMOKE_DRY_RUN:-0}" = 1 ]; then
    echo "Dry-run does not send an HTTP request."
    exit 0
fi

APP_BASE_URL="${base_url}" "${project_root}/docker/scripts/smoke-check.sh"
