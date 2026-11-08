#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH='' cd -- "${script_dir}/../../.." && pwd)
temporary_root=$(mktemp -d)

cleanup() {
    rm -rf -- "${temporary_root}"
}
trap cleanup EXIT HUP INT TERM

secrets_file="${temporary_root}/prod.secrets.env"
COMPOSE_ENV_FILE="${secrets_file}" "${project_root}/docker/release/init-deployment.sh" >/dev/null

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
