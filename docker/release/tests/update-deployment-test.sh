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
tagged_image=ghcr.io/wolfcharaa/geo-registry/app:v0.2.0-linux-amd64
exact_image="ghcr.io/wolfcharaa/geo-registry/app@sha256:${digest}"
state_file="${temporary_root}/docker.state"
log_file="${temporary_root}/docker.log"

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
x-app-image: &app-image "${tagged_image}"
x-app-environment: &app-environment
  APP_VERSION: v0.2.0
services: {}
EOF

run_update() {
    PATH="${fixture_dir}:${PATH}" \
    FAKE_DOCKER_STATE="${state_file}" \
    FAKE_DOCKER_LOG="${log_file}" \
    FAKE_DOCKER_EXACT_LOADED=1 \
    FAKE_DOCKER_EXACT_IMAGE="${exact_image}" \
    FAKE_DOCKER_VERSION=v0.2.0 \
    COMPOSE_ENV_FILE="${temporary_root}/prod.secrets.env" \
    RELEASE_ENV_FILE="${temporary_root}/release.env" \
    PRODUCTION_COMPOSE_MODEL="${temporary_root}/compose.yaml" \
    DOCKER_PLATFORM=linux/amd64 \
        "${project_root}/docker/release/update-deployment.sh" "$@"
}

: >"${log_file}"
preflight_output=$(DEPLOY_UPDATE_DRY_RUN=1 run_update)
printf '%s\n' "${preflight_output}" | grep -Fq 'Deployment update preflight passed.'
printf '%s\n' "${preflight_output}" | grep -Fq 'Dry-run leaves containers, database and worker control state unchanged.'
if grep -Eq 'worker:pause|migrate:up| stop | up ' "${log_file}"; then
    echo 'Deployment update test failed: dry-run attempted a mutating command.' >&2
    exit 1
fi

: >"${log_file}"
update_output=$(run_update --yes)
printf '%s\n' "${update_output}" | grep -Fq 'Deployment activation completed: v0.2.0.'
grep -Fq 'worker:pause' "${log_file}"
grep -Fq 'migrate:up' "${log_file}"
grep -Fq 'gar:integrity' "${log_file}"
grep -Fq 'gar:status' "${log_file}"
grep -Fq 'worker:resume' "${log_file}"

echo 'Deployment update contract test passed.'
