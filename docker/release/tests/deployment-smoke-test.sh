#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH='' cd -- "${script_dir}/../../.." && pwd)
fixture_dir="${script_dir}/fixtures"
temporary_root=$(mktemp -d)

# shellcheck disable=SC1091
. "${script_dir}/test-lib.sh"

cleanup() {
    rm -rf -- "${temporary_root}"
}
trap cleanup EXIT HUP INT TERM

curl_log="${temporary_root}/curl.log"
docker_log="${temporary_root}/docker.log"

cat >"${temporary_root}/prod.secrets.env" <<EOF
GEO_REGISTRY_DATABASE_PASSWORD=test-database-password
GEO_REGISTRY_POSTGRES_ADMIN_PASSWORD=test-admin-password
EOF
cat >"${temporary_root}/release.env" <<EOF
DOCKER_IMAGE_PREFIX=ghcr.io/wolfcharaa/geo-registry
APP_IMAGE_TAG=v0.2.5-linux-amd64
APP_IMAGE_DIGEST=sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
RELEASE_VERSION=v0.2.5
COMPOSE_PROJECT_NAME=geo-registry
EOF
cat >"${temporary_root}/compose.yaml" <<'EOF'
x-app-image: &app-image "ghcr.io/wolfcharaa/geo-registry/app:v0.2.5-linux-amd64"
x-app-environment: &app-environment
  APP_VERSION: v0.2.5
services: {}
EOF
cat >"${temporary_root}/compose.json" <<'EOF'
{
  "services": {
    "app": {
      "ports": [
        {
          "host_ip": "10.20.30.40",
          "target": 8080,
          "published": "18080",
          "protocol": "tcp"
        }
      ]
    }
  }
}
EOF

reset_evidence() {
    evidence_file=$1
    cat >"${evidence_file}" <<'EOF'
smoke_status=in-progress
smoke_endpoint=pending
smoke_connect_timeout_seconds=pending
smoke_request_timeout_seconds=pending
smoke_deadline_seconds=pending
smoke_health_status=pending
smoke_address_search_status=pending
EOF
}

run_smoke() {
    evidence_file=$1
    PATH="${fixture_dir}:${PATH}" \
    FAKE_DOCKER_STATE="${temporary_root}/docker.state" \
    FAKE_DOCKER_LOG="${docker_log}" \
    FAKE_DOCKER_COMPOSE_CONFIG_FILE="${temporary_root}/compose.json" \
    FAKE_CURL_LOG="${curl_log}" \
    FAKE_CURL_FAIL_MATCH="${FAKE_CURL_FAIL_MATCH:-}" \
    FAKE_CURL_FAIL_CODE="${FAKE_CURL_FAIL_CODE:-28}" \
    FAKE_CURL_SEARCH_RESPONSE="${FAKE_CURL_SEARCH_RESPONSE:-}" \
    COMPOSE_ENV_FILE="${temporary_root}/prod.secrets.env" \
    RELEASE_ENV_FILE="${temporary_root}/release.env" \
    PRODUCTION_COMPOSE_MODEL="${temporary_root}/compose.yaml" \
    DEPLOY_EVIDENCE_FILE="${evidence_file}" \
    DEPLOY_SMOKE_CONNECT_TIMEOUT_SECONDS=3 \
    DEPLOY_SMOKE_REQUEST_TIMEOUT_SECONDS=10 \
    DEPLOY_SMOKE_DEADLINE_SECONDS=45 \
        "${project_root}/docker/release/deployment-smoke.sh"
}

success_evidence="${temporary_root}/success.env"
reset_evidence "${success_evidence}"
: >"${curl_log}"
: >"${docker_log}"
success_output=$(run_smoke "${success_evidence}")
printf '%s\n' "${success_output}" \
    | grep -Fq 'Deployment smoke passed: health and representative address search.'
[ "$(wc -l <"${curl_log}" | tr -d ' ')" -eq 4 ]
[ "$(grep -Fc -- '--connect-timeout 3 --max-time 10' "${curl_log}")" -eq 4 ]
grep -Fq 'q=Химки Лавочкина 17' "${curl_log}"
grep -Fq 'region_code=50' "${curl_log}"
grep -Fq 'smoke_status=passed' "${success_evidence}"
grep -Fq 'smoke_endpoint=http://10.20.30.40:18080/api/addresses/search' \
    "${success_evidence}"
grep -Fq 'smoke_connect_timeout_seconds=3' "${success_evidence}"
grep -Fq 'smoke_request_timeout_seconds=10' "${success_evidence}"
grep -Fq 'smoke_deadline_seconds=45' "${success_evidence}"
grep -Fq 'smoke_health_status=passed' "${success_evidence}"
grep -Fq 'smoke_address_search_status=passed' "${success_evidence}"

timeout_evidence="${temporary_root}/timeout.env"
reset_evidence "${timeout_evidence}"
: >"${curl_log}"
: >"${docker_log}"
if timeout_output=$(FAKE_CURL_FAIL_MATCH=/api/addresses/search \
    run_smoke "${timeout_evidence}" 2>&1); then
    echo 'Deployment smoke test failed: address-search timeout was ignored.' >&2
    exit 1
fi
printf '%s\n' "${timeout_output}" \
    | grep -Fq 'deployment address-search request failed or exceeded the smoke deadline'
grep -Fq 'smoke_status=failed' "${timeout_evidence}"
grep -Fq 'smoke_health_status=passed' "${timeout_evidence}"
grep -Fq 'smoke_address_search_status=failed' "${timeout_evidence}"
contract_assert_docker_log_read_only \
    "${docker_log}" 'deployment smoke timeout branch'

contract_evidence="${temporary_root}/contract.env"
reset_evidence "${contract_evidence}"
if contract_output=$(FAKE_CURL_SEARCH_RESPONSE='{"success":true,"suggestions":[],"meta":{"endpoint":"search"}}' \
    run_smoke "${contract_evidence}" 2>&1); then
    echo 'Deployment smoke test failed: empty address-search response was accepted.' >&2
    exit 1
fi
printf '%s\n' "${contract_output}" \
    | grep -Fq 'does not match the representative public contract'
grep -Fq 'smoke_address_search_status=failed-contract' "${contract_evidence}"

echo 'Deployment bounded smoke contract test passed.'
