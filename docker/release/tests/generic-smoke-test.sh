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
output_file="${temporary_root}/output.log"

run_smoke() {
    PATH="${fixture_dir}:${PATH}" \
    FAKE_CURL_LOG="${curl_log}" \
    FAKE_CURL_FAIL_MATCH="${FAKE_CURL_FAIL_MATCH:-}" \
    APP_BASE_URL=http://127.0.0.1:18080 \
    SMOKE_CONNECT_TIMEOUT_SECONDS="${SMOKE_CONNECT_TIMEOUT_SECONDS:-3}" \
    SMOKE_REQUEST_TIMEOUT_SECONDS="${SMOKE_REQUEST_TIMEOUT_SECONDS:-10}" \
        "${project_root}/docker/scripts/smoke-check.sh"
}

: >"${curl_log}"
run_smoke >"${output_file}"
contract_assert_file_contains \
    'Smoke checks passed: http://127.0.0.1:18080' "${output_file}" \
    'successful generic smoke output'
contract_assert_file_line_count 3 "${curl_log}" 'successful generic smoke requests'
[ "$(grep -Fc -- '--connect-timeout 3 --max-time 10 --output /dev/null' "${curl_log}")" -eq 3 ] \
    || contract_test_fail 'every generic smoke request must be bounded'
contract_assert_file_contains '/health/live' "${curl_log}" 'liveness smoke request'
contract_assert_file_contains '/health/startup' "${curl_log}" 'startup smoke request'
contract_assert_file_contains '/health/ready' "${curl_log}" 'readiness smoke request'

: >"${curl_log}"
if FAKE_CURL_FAIL_MATCH=/health/startup run_smoke >"${output_file}" 2>&1; then
    contract_test_fail 'generic smoke ignored a failed startup request'
fi
contract_assert_file_line_count 2 "${curl_log}" 'failed generic smoke stops immediately'

: >"${curl_log}"
if SMOKE_CONNECT_TIMEOUT_SECONDS=0 run_smoke >"${output_file}" 2>&1; then
    contract_test_fail 'generic smoke accepted a zero connect timeout'
fi
contract_assert_file_contains \
    'timeouts must be positive integer seconds' "${output_file}" \
    'generic smoke timeout validation'
contract_assert_file_empty "${curl_log}" 'invalid timeout must fail before HTTP requests'

echo 'Generic bounded smoke contract test passed.'
