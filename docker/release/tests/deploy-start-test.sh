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
tagged_image=ghcr.io/wolfcharaa/geo-registry/app:v0.2.5-linux-amd64
exact_image="ghcr.io/wolfcharaa/geo-registry/app@sha256:${digest}"
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
cat >"${temporary_root}/compose.yaml" <<EOF
x-app-image: &app-image "${tagged_image}"
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
          "host_ip": "127.0.0.1",
          "target": 8080,
          "published": "18080",
          "protocol": "tcp"
        }
      ]
    }
  }
}
EOF

run_start() {
    PATH="${fixture_dir}:${PATH}" \
    FAKE_DOCKER_STATE="${temporary_root}/docker.state" \
    FAKE_DOCKER_LOG="${log_file}" \
    FAKE_DOCKER_EXACT_LOADED=1 \
    FAKE_DOCKER_EXACT_IMAGE="${exact_image}" \
    FAKE_DOCKER_VERSION=v0.2.5 \
    FAKE_DOCKER_COMPOSE_CONFIG_FILE="${temporary_root}/compose.json" \
    FAKE_SS_LISTENERS='' \
    COMPOSE_ENV_FILE="${temporary_root}/prod.secrets.env" \
    RELEASE_ENV_FILE="${temporary_root}/release.env" \
    RELEASE_COMPOSE_FILE="${temporary_root}/compose.yaml" \
    PRODUCTION_COMPOSE_MODEL="${temporary_root}/compose.yaml" \
    DEPLOY_TARGET="${TEST_DEPLOY_TARGET-moscow-primary}" \
    DEPLOY_EXPECTED_DOCKER_CONTEXT=production \
    DEPLOY_CONFIRM_TARGET="${TEST_CONFIRM_TARGET-moscow-primary}" \
    DEPLOY_STATE_ROOT="${temporary_root}/deployment-state" \
    DEPLOY_SMOKE_DRY_RUN=1 \
    DOCKER_PLATFORM=linux/amd64 \
        "${project_root}/docker/release/deploy-start.sh"
}

: >"${log_file}"
if blocked_output=$(TEST_DEPLOY_TARGET='' TEST_CONFIRM_TARGET='' run_start 2>&1); then
    echo 'Deployment start test failed: an implicit target was accepted.' >&2
    exit 1
fi
printf '%s\n' "${blocked_output}" | grep -Fq 'DEPLOY_TARGET must be an explicit label'
if grep -Eq ' run | up | stop | load | pull ' "${log_file}"; then
    echo 'Deployment start test failed: blocked target reached a mutation.' >&2
    exit 1
fi

: >"${log_file}"
start_output=$(run_start)
printf '%s\n' "${start_output}" | grep -Fq '==> [1/8] Checking the configured HTTP host port'
printf '%s\n' "${start_output}" | grep -Fq 'State: present-needs-application-validation'
printf '%s\n' "${start_output}" | grep -Fq 'Release v0.2.5 verified; dry-run leaves containers unchanged.'
printf '%s\n' "${start_output}" | grep -Fq '==> [7/8] Activating application and worker processes'
printf '%s\n' "${start_output}" | grep -Fq 'Dry-run does not send an HTTP request.'
printf '%s\n' "${start_output}" | grep -Fq 'Deployment start completed: v0.2.5.'
[ "$(printf '%s\n' "${start_output}" \
    | grep -Fc 'Deployment target lock acquired:')" -eq 1 ]
grep -Fq 'migrate:history' "${log_file}"
grep -Fq 'gar:integrity' "${log_file}"
grep -Fq 'gar:status' "${log_file}"
grep -Fq 'message-bus:registry:check' "${log_file}"
grep -Fq ' up --detach --force-recreate --wait --wait-timeout 120 app worker-address worker-runtime --no-build --pull never' \
    "${log_file}"
evidence_file="${temporary_root}/deployment-state/moscow-primary/current.env"
grep -Fq 'state=success' "${evidence_file}"
grep -Fq 'operation=deploy-start' "${evidence_file}"
grep -Fq 'artifact_status=verified' "${evidence_file}"
grep -Fq 'migration_status=history-checked' "${evidence_file}"
grep -Fq 'data_checks_status=passed' "${evidence_file}"
grep -Fq 'activation_status=passed' "${evidence_file}"
grep -Fq 'smoke_status=passed' "${evidence_file}"
grep -Fq 'smoke_endpoint=http://127.0.0.1:18080/api/addresses/search' \
    "${evidence_file}"
grep -Fq 'smoke_deadline_seconds=45' "${evidence_file}"
grep -Fq 'smoke_health_status=dry-run' "${evidence_file}"
grep -Fq 'smoke_address_search_status=dry-run' "${evidence_file}"

echo 'Deployment start orchestration contract test passed.'
