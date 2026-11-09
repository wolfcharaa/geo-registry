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
backup_directory="${temporary_root}/backups/application-postgres-current"
mkdir -p "${backup_directory}"
printf '%s\n' 'fake-pgdump-archive' >"${backup_directory}/application-postgres.dump"
backup_bytes=$(wc -c <"${backup_directory}/application-postgres.dump" | tr -d ' ')
backup_sha256=$(sha256sum "${backup_directory}/application-postgres.dump" | awk '{print $1}')

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

cat >"${backup_directory}/manifest.txt" <<EOF
format=geo-registry-postgres-backup-v1
created_at=2026-11-09T12:00:00Z
component=application-postgres
application_revision=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
container_image=postgis:17-test
container_id=sha256:postgres-test
database_name=geo_registry
database_user=geo_registry
database_size_bytes=1048576
server_version=17.6
server_version_num=170006
pg_dump_version=17.6
dump_format=custom
dump_compression=zstd:6
dump_file=application-postgres.dump
dump_bytes=${backup_bytes}
dump_sha256=${backup_sha256}
provenance_name=canonical-gar-database
provenance_sha256=none
EOF

run_update() {
    test_backup_directory=${TEST_DEPLOY_BACKUP_DIR-${backup_directory}}
    test_schema_compatibility=${TEST_SCHEMA_COMPATIBILITY-backward-compatible}
    PATH="${fixture_dir}:${PATH}" \
    FAKE_DOCKER_STATE="${state_file}" \
    FAKE_DOCKER_LOG="${log_file}" \
    FAKE_DOCKER_EXACT_LOADED=1 \
    FAKE_DOCKER_EXACT_IMAGE="${exact_image}" \
    FAKE_DOCKER_VERSION=v0.2.0 \
    FAKE_DOCKER_COMPOSE_CONFIG_FILE="${temporary_root}/compose.json" \
    FAKE_DOCKER_APP_CONTAINER="${FAKE_DOCKER_APP_CONTAINER-geo-registry-app-test}" \
    FAKE_DOCKER_PORT_BINDINGS="${FAKE_DOCKER_PORT_BINDINGS-127.0.0.1:18080}" \
    FAKE_DOCKER_PORT_OWNERS="${FAKE_DOCKER_PORT_OWNERS:-}" \
    FAKE_DOCKER_FAIL_MATCH="${FAKE_DOCKER_FAIL_MATCH:-}" \
    FAKE_SS_LISTENERS="${FAKE_SS_LISTENERS-LISTEN 0 4096 127.0.0.1:18080 0.0.0.0:*}" \
    COMPOSE_ENV_FILE="${temporary_root}/prod.secrets.env" \
    RELEASE_ENV_FILE="${temporary_root}/release.env" \
    PRODUCTION_COMPOSE_MODEL="${temporary_root}/compose.yaml" \
    DEPLOY_TARGET=moscow-primary \
    DEPLOY_EXPECTED_DOCKER_CONTEXT=production \
    DEPLOY_CONFIRM_TARGET=moscow-primary \
    DEPLOY_STATE_ROOT="${temporary_root}/deployment-state" \
    DEPLOY_SMOKE_DRY_RUN=1 \
    DEPLOY_BACKUP_DIR="${test_backup_directory}" \
    DEPLOY_SCHEMA_COMPATIBILITY="${test_schema_compatibility}" \
    DOCKER_PLATFORM=linux/amd64 \
        "${project_root}/docker/release/update-deployment.sh" "$@"
}

: >"${log_file}"
preflight_output=$(DEPLOY_UPDATE_DRY_RUN=1 run_update)
printf '%s\n' "${preflight_output}" | grep -Fq 'State: occupied-by-managed-app-pending-rebind'
printf '%s\n' "${preflight_output}" | grep -Fq 'Standalone preflight: run make deploy-update DOCKER_PLATFORM=linux/amd64.'
printf '%s\n' "${preflight_output}" | grep -Fq 'Deployment update preflight passed.'
printf '%s\n' "${preflight_output}" \
    | grep -Fq 'Current application source OID: bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
printf '%s\n' "${preflight_output}" | grep -Fq "Verified backup: ${backup_directory}"
printf '%s\n' "${preflight_output}" | grep -Fq 'Schema compatibility: backward-compatible'
printf '%s\n' "${preflight_output}" \
    | grep -Fq 'Permitted recovery: binary-rollback-after-verification'
printf '%s\n' "${preflight_output}" | grep -Fq 'Dry-run leaves containers, database and worker control state unchanged.'
if grep -Eq 'worker:pause|migrate:up| stop | up ' "${log_file}"; then
    echo 'Deployment update test failed: dry-run attempted a mutating command.' >&2
    exit 1
fi

if missing_backup_output=$(TEST_DEPLOY_BACKUP_DIR='' \
    DEPLOY_UPDATE_DRY_RUN=1 run_update 2>&1); then
    echo 'Deployment update test failed: preflight accepted a missing backup binding.' >&2
    exit 1
fi
printf '%s\n' "${missing_backup_output}" \
    | grep -Fq 'DEPLOY_BACKUP_DIR must select the verified backup'

if schema_output=$(TEST_SCHEMA_COMPATIBILITY=unknown \
    DEPLOY_UPDATE_DRY_RUN=1 run_update 2>&1); then
    echo 'Deployment update test failed: preflight accepted unclassified schema compatibility.' >&2
    exit 1
fi
printf '%s\n' "${schema_output}" \
    | grep -Fq 'DEPLOY_SCHEMA_COMPATIBILITY must be none, backward-compatible, expand-contract or forward-only'

: >"${log_file}"
if conflict_output=$(
    FAKE_DOCKER_APP_CONTAINER='' \
    FAKE_DOCKER_PORT_BINDINGS='' \
    FAKE_DOCKER_PORT_OWNERS='container=other-http image=example/http ports=10.20.30.40:18080->80/tcp' \
    FAKE_SS_LISTENERS='LISTEN 0 4096 10.20.30.40:18080 0.0.0.0:*' \
    DEPLOY_UPDATE_DRY_RUN=1 run_update 2>&1
); then
    echo 'Deployment update test failed: foreign port conflict passed preflight.' >&2
    exit 1
fi
printf '%s\n' "${conflict_output}" | grep -Fq 'State: conflict'
printf '%s\n' "${conflict_output}" | grep -Fq 'container=other-http'
if grep -Eq 'worker:pause|migrate:up| stop | up ' "${log_file}"; then
    echo 'Deployment update test failed: port conflict attempted a mutating command.' >&2
    exit 1
fi

: >"${log_file}"
update_output=$(run_update --yes)
printf '%s\n' "${update_output}" | grep -Fq 'Deployment activation completed: v0.2.0.'
printf '%s\n' "${update_output}" \
    | grep -Fq 'Running HTTP smoke checks while the deployment target lock is held.'
printf '%s\n' "${update_output}" | grep -Fq 'Dry-run does not send an HTTP request.'
grep -Fq 'worker:pause' "${log_file}"
grep -Fq 'migrate:up' "${log_file}"
grep -Fq 'gar:integrity' "${log_file}"
grep -Fq 'gar:status' "${log_file}"
grep -Fq 'worker:resume' "${log_file}"

target_state="${temporary_root}/deployment-state/moscow-primary"
grep -Fq 'state=success' "${target_state}/current.env"
grep -Fq 'operation=deploy-update' "${target_state}/current.env"
grep -Fq 'backup_status=verified' "${target_state}/current.env"
grep -Fq 'schema_compatibility=backward-compatible' "${target_state}/current.env"
grep -Fq 'migration_status=applied' "${target_state}/current.env"
grep -Fq 'smoke_status=passed' "${target_state}/current.env"

if failure_output=$(FAKE_DOCKER_FAIL_MATCH=migrate:up run_update --yes 2>&1); then
    echo 'Deployment update test failed: injected migration failure was ignored.' >&2
    exit 1
fi
printf '%s\n' "${failure_output}" \
    | grep -Fq 'Deployment partial state requires handover:'
grep -Fq 'state=partial' "${target_state}/partial.env"
grep -Fq 'failed_step=migrations' "${target_state}/partial.env"
grep -Fq 'recovery_branch=binary-rollback-after-verification' \
    "${target_state}/partial.env"

if partial_output=$(run_update --yes 2>&1); then
    echo 'Deployment update test failed: partial state did not block a later update.' >&2
    exit 1
fi
printf '%s\n' "${partial_output}" \
    | grep -Fq 'requires an explicit recovery handover'

echo 'Deployment update contract test passed.'
