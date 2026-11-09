#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)

# shellcheck disable=SC1091
. "${script_dir}/release-lib.sh"
# shellcheck disable=SC1091
. "${script_dir}/deployment-lib.sh"

for command_name in curl date grep mktemp rm tr; do
    release_require_command "${command_name}"
done

case "${DEPLOY_SMOKE_DRY_RUN:-0}" in
    0|1) ;;
    *) release_fail "DEPLOY_SMOKE_DRY_RUN must be 0 or 1" ;;
esac

connect_timeout=${DEPLOY_SMOKE_CONNECT_TIMEOUT_SECONDS:-3}
request_timeout=${DEPLOY_SMOKE_REQUEST_TIMEOUT_SECONDS:-10}
deadline_seconds=${DEPLOY_SMOKE_DEADLINE_SECONDS:-45}
for timeout_value in "${connect_timeout}" "${request_timeout}" "${deadline_seconds}"; do
    case "${timeout_value}" in
        ''|*[!0-9]*) release_fail "deployment smoke timeouts must be positive integer seconds" ;;
    esac
    [ "${timeout_value}" -gt 0 ] \
        || release_fail "deployment smoke timeouts must be positive integer seconds"
done

deployment_smoke_evidence() {
    [ -n "${DEPLOY_EVIDENCE_FILE:-}" ] || return 0
    deployment_evidence_update "$@"
}

smoke_started_epoch=$(date -u '+%s')
smoke_deadline_epoch=$((smoke_started_epoch + deadline_seconds))

deployment_smoke_request() {
    smoke_now_epoch=$(date -u '+%s')
    smoke_remaining_seconds=$((smoke_deadline_epoch - smoke_now_epoch))
    [ "${smoke_remaining_seconds}" -gt 0 ] || return 124

    smoke_request_timeout=${request_timeout}
    if [ "${smoke_remaining_seconds}" -lt "${smoke_request_timeout}" ]; then
        smoke_request_timeout=${smoke_remaining_seconds}
    fi
    smoke_connect_timeout=${connect_timeout}
    if [ "${smoke_request_timeout}" -lt "${smoke_connect_timeout}" ]; then
        smoke_connect_timeout=${smoke_request_timeout}
    fi

    curl --fail --silent --show-error \
        --connect-timeout "${smoke_connect_timeout}" \
        --max-time "${smoke_request_timeout}" \
        "$@"
}

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
search_endpoint=$(deployment_sanitize_endpoint \
    "${base_url}/api/addresses/search")

deployment_smoke_evidence \
    smoke_endpoint "${search_endpoint}" \
    smoke_connect_timeout_seconds "${connect_timeout}" \
    smoke_request_timeout_seconds "${request_timeout}" \
    smoke_deadline_seconds "${deadline_seconds}"

echo "Deployment HTTP smoke target: ${base_url} (container port ${target_port})"
echo "Smoke deadline: ${deadline_seconds}s; request timeout: ${request_timeout}s; connect timeout: ${connect_timeout}s"
if [ "${DEPLOY_SMOKE_DRY_RUN:-0}" = 1 ]; then
    echo "Dry-run does not send an HTTP request."
    deployment_smoke_evidence \
        smoke_health_status dry-run \
        smoke_address_search_status dry-run \
        smoke_status passed
    exit 0
fi

for health_path in /health/live /health/startup /health/ready; do
    if ! deployment_smoke_request --output /dev/null \
        "${base_url}${health_path}"; then
        deployment_smoke_evidence \
            smoke_health_status failed \
            smoke_address_search_status not-run \
            smoke_status failed
        release_fail "deployment health request failed or exceeded the smoke deadline: ${health_path}"
    fi
done
deployment_smoke_evidence smoke_health_status passed

smoke_response=$(mktemp)
smoke_response_compact=$(mktemp)
cleanup_deployment_smoke() {
    rm -f "${smoke_response}" "${smoke_response_compact}"
}
trap cleanup_deployment_smoke EXIT HUP INT TERM

if ! deployment_smoke_request \
    --header 'Accept: application/json' \
    --get \
    --data-urlencode 'q=Химки Лавочкина 17' \
    --data 'hier=adm' \
    --data 'limit=1' \
    --data 'region_code=50' \
    --output "${smoke_response}" \
    "${search_endpoint}"; then
    deployment_smoke_evidence \
        smoke_address_search_status failed \
        smoke_status failed
    release_fail "deployment address-search request failed or exceeded the smoke deadline"
fi

tr -d '[:space:]' <"${smoke_response}" >"${smoke_response_compact}"
if ! grep -Fq '"success":true' "${smoke_response_compact}" \
    || ! grep -Fq '"endpoint":"search"' "${smoke_response_compact}" \
    || ! grep -Fq '"suggestions":[{' "${smoke_response_compact}" \
    || ! grep -Eq '"object_id":43685301([,}])' "${smoke_response_compact}"; then
    deployment_smoke_evidence \
        smoke_address_search_status failed-contract \
        smoke_status failed
    release_fail "deployment address-search response does not match the representative public contract"
fi

deployment_smoke_evidence \
    smoke_address_search_status passed \
    smoke_status passed
echo "Deployment smoke passed: health and representative address search."
