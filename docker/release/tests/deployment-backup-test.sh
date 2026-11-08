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

digest=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
state_file="${temporary_root}/docker.state"
log_file="${temporary_root}/docker.log"
mkdir -p "${temporary_root}/backups"

cat >"${temporary_root}/prod.secrets.env" <<EOF
GEO_REGISTRY_DATABASE_PASSWORD=test-database-password
GEO_REGISTRY_POSTGRES_ADMIN_PASSWORD=test-admin-password
EOF

cat >"${temporary_root}/release.env" <<EOF
DOCKER_IMAGE_PREFIX=ghcr.io/wolfcharaa/geo-registry
APP_IMAGE_TAG=v0.2.0-linux-amd64
APP_IMAGE_DIGEST=sha256:${digest}
RELEASE_VERSION=v0.2.0
COMPOSE_PROJECT_NAME=geo-registry
EOF

cat >"${temporary_root}/compose.yaml" <<EOF
x-app-image: &app-image "ghcr.io/wolfcharaa/geo-registry/app:v0.2.0-linux-amd64"
x-app-environment: &app-environment
  APP_VERSION: v0.2.0
services: {}
EOF

output=$(
    PATH="${fixture_dir}:${PATH}" \
    FAKE_DOCKER_STATE="${state_file}" \
    FAKE_DOCKER_LOG="${log_file}" \
    COMPOSE_ENV_FILE="${temporary_root}/prod.secrets.env" \
    RELEASE_ENV_FILE="${temporary_root}/release.env" \
    PRODUCTION_COMPOSE_MODEL="${temporary_root}/compose.yaml" \
    BACKUP_ROOT="${temporary_root}/backups" \
    DEPLOY_BACKUP_DRY_RUN=1 \
        "${project_root}/docker/release/create-deployment-backup.sh"
)

printf '%s\n' "${output}" | grep -Fq 'Backup preflight passed.'
printf '%s\n' "${output}" | grep -Fq 'Component: application-postgres'
grep -Fq 'ps --quiet postgres' "${log_file}"
grep -Fq -- '--username geo_registry --dbname geo_registry' "${log_file}"
if grep -Fq -- '--format=custom' "${log_file}"; then
    echo 'Deployment backup test failed: dry-run attempted pg_dump.' >&2
    exit 1
fi

echo 'Deployment backup contract test passed.'
