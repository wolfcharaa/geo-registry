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
state_root="${temporary_root}/deployment-state"
ready_file="${temporary_root}/lock.ready"
release_file="${temporary_root}/lock.release"
log_file="${temporary_root}/docker.log"

cat >"${temporary_root}/prod.secrets.env" <<EOF
GEO_REGISTRY_DATABASE_PASSWORD=test-database-password
GEO_REGISTRY_POSTGRES_ADMIN_PASSWORD=test-admin-password
EOF
cat >"${temporary_root}/release.env" <<EOF
DOCKER_IMAGE_PREFIX=ghcr.io/wolfcharaa/geo-registry
APP_IMAGE_TAG=v0.2.5-linux-amd64
APP_IMAGE_DIGEST=sha256:${digest}
RELEASE_VERSION=v0.2.5
COMPOSE_PROJECT_NAME=geo-registry
EOF
cat >"${temporary_root}/compose.yaml" <<'EOF'
x-app-image: &app-image "ghcr.io/wolfcharaa/geo-registry/app:v0.2.5-linux-amd64"
x-app-environment: &app-environment
  APP_VERSION: v0.2.5
services: {}
EOF

lock_environment() {
    PATH="${fixture_dir}:${PATH}"
    export PATH
    FAKE_DOCKER_STATE="${temporary_root}/docker.state"
    FAKE_DOCKER_LOG="${log_file}"
    COMPOSE_ENV_FILE="${temporary_root}/prod.secrets.env"
    RELEASE_ENV_FILE="${temporary_root}/release.env"
    PRODUCTION_COMPOSE_MODEL="${temporary_root}/compose.yaml"
    DEPLOY_TARGET=moscow-primary
    DEPLOY_EXPECTED_DOCKER_CONTEXT=production
    DEPLOY_CONFIRM_TARGET=moscow-primary
    DEPLOY_STATE_ROOT="${state_root}"
    DEPLOY_SERVICE_SCOPE=application
    DEPLOY_DATA_MODE=update
    export FAKE_DOCKER_STATE FAKE_DOCKER_LOG COMPOSE_ENV_FILE RELEASE_ENV_FILE
    export PRODUCTION_COMPOSE_MODEL DEPLOY_TARGET DEPLOY_EXPECTED_DOCKER_CONTEXT
    export DEPLOY_CONFIRM_TARGET DEPLOY_STATE_ROOT DEPLOY_SERVICE_SCOPE DEPLOY_DATA_MODE
}

hold_lock() (
    lock_environment
    # shellcheck disable=SC1091
    . "${project_root}/docker/release/release-lib.sh"
    # shellcheck disable=SC1091
    . "${project_root}/docker/release/deployment-lib.sh"
    deployment_require_confirmed_target \
        test-hold application update "${temporary_root}/release.env"
    deployment_acquire_target_lock \
        test-hold application update "${temporary_root}/release.env"
    : >"${ready_file}"
    while [ ! -f "${release_file}" ]; do
        sleep 0.1
    done
)

hold_lock >"${temporary_root}/holder.out" 2>"${temporary_root}/holder.err" &
holder_pid=$!
ready_attempt=0
while [ ! -f "${ready_file}" ] && [ "${ready_attempt}" -lt 50 ]; do
    sleep 0.1
    ready_attempt=$((ready_attempt + 1))
done
[ -f "${ready_file}" ] || {
    kill "${holder_pid}" >/dev/null 2>&1 || true
    wait "${holder_pid}" >/dev/null 2>&1 || true
    echo 'Deployment lock test failed: holder did not acquire the lock.' >&2
    exit 1
}

if contention_output=$(hold_lock 2>&1); then
    : >"${release_file}"
    wait "${holder_pid}"
    echo 'Deployment lock test failed: a concurrent owner acquired the same target.' >&2
    exit 1
fi
printf '%s\n' "${contention_output}" | grep -Fq 'Active deployment target lock:'
printf '%s\n' "${contention_output}" \
    | grep -Fq 'another deployment operation owns target moscow-primary'
printf '%s\n' "${contention_output}" | grep -Fq 'operation=test-hold'

: >"${release_file}"
wait "${holder_pid}"

lock_file="${state_root}/moscow-primary/deployment.lock"
grep -Fq 'lock_format=1' "${lock_file}"
grep -Fq 'target=moscow-primary' "${lock_file}"
grep -Fq 'docker_context=production' "${lock_file}"
grep -Fq 'compose_project=geo-registry' "${lock_file}"

inherited_output=$(
    lock_environment
    # shellcheck disable=SC1091
    . "${project_root}/docker/release/release-lib.sh"
    # shellcheck disable=SC1091
    . "${project_root}/docker/release/deployment-lib.sh"
    deployment_require_confirmed_target \
        parent-update application update "${temporary_root}/release.env"
    deployment_acquire_target_lock \
        parent-update application update "${temporary_root}/release.env"
    "${project_root}/docker/compose/prod.sh" up --detach app
)
[ "$(printf '%s\n' "${inherited_output}" \
    | grep -Fc 'Deployment target lock acquired:')" -eq 1 ]
grep -Fq ' up --detach app --no-build --pull never' "${log_file}"

echo 'Deployment per-target lock contract test passed.'
