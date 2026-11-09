#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH='' cd -- "${script_dir}/../../.." && pwd)
fixture_dir="${script_dir}/fixtures"
temporary_root=$(mktemp -d)

cleanup() {
    rm -rf -- "${temporary_root}"
}
trap cleanup EXIT HUP INT TERM

secrets_file="${temporary_root}/prod.secrets.env"
blocked_secrets_file="${temporary_root}/blocked-prod.secrets.env"
if blocked_output=$(PATH="${fixture_dir}:${PATH}" \
    FAKE_DOCKER_STATE="${temporary_root}/docker.state" \
    COMPOSE_ENV_FILE="${blocked_secrets_file}" \
    "${project_root}/docker/release/init-deployment.sh" 2>&1); then
    echo 'Deployment secret initialization test failed: an implicit target was accepted.' >&2
    exit 1
fi
printf '%s\n' "${blocked_output}" | grep -Fq 'DEPLOY_TARGET must be an explicit label'
[ ! -e "${blocked_secrets_file}" ] || {
    echo 'Deployment secret initialization test failed: blocked target created a secret file.' >&2
    exit 1
}

PATH="${fixture_dir}:${PATH}" \
FAKE_DOCKER_STATE="${temporary_root}/docker.state" \
COMPOSE_ENV_FILE="${secrets_file}" \
DEPLOY_TARGET=moscow-primary \
DEPLOY_EXPECTED_DOCKER_CONTEXT=production \
DEPLOY_CONFIRM_TARGET=moscow-primary \
DEPLOY_STATE_ROOT="${temporary_root}/deployment-state" \
    "${project_root}/docker/release/init-deployment.sh" >/dev/null

[ "$(stat -c %a "${secrets_file}")" = 600 ]
grep -Eq '^GEO_REGISTRY_DATABASE_PASSWORD=[0-9a-f]{64}$' "${secrets_file}"
grep -Eq '^GEO_REGISTRY_POSTGRES_ADMIN_PASSWORD=[0-9a-f]{64}$' "${secrets_file}"
grep -Fxq 'GEO_REGISTRY_DATAMOS_API_KEY=' "${secrets_file}"
grep -Fxq 'GEO_REGISTRY_OPENSEARCH_USERNAME=' "${secrets_file}"
grep -Fxq 'GEO_REGISTRY_OPENSEARCH_PASSWORD=' "${secrets_file}"

first_checksum=$(sha256sum "${secrets_file}")
COMPOSE_ENV_FILE="${secrets_file}" "${project_root}/docker/release/init-deployment.sh" >/dev/null
[ "$(sha256sum "${secrets_file}")" = "${first_checksum}" ]

echo 'Deployment secret initialization test passed.'
