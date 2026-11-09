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

digest=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
tagged_image=ghcr.io/wolfcharaa/geo-registry/app:v0.2.5-linux-amd64
exact_image="ghcr.io/wolfcharaa/geo-registry/app@sha256:${digest}"
backup_directory="${temporary_root}/backups/application-postgres-current"
log_file="${temporary_root}/docker.log"
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

run_plan() {
    test_source_target=${TEST_RECOVERY_SOURCE_TARGET-moscow-primary}
    test_target=${TEST_DEPLOY_TARGET-moscow-rehearsal}
    test_copy=${TEST_OFF_HOST_COPY-vault-copy-20261109}
    test_nominatim=${TEST_NOMINATIM_STRATEGY-rebuild}
    test_database_exists=${TEST_DATABASE_EXISTS-0}
    test_container_running=${TEST_CONTAINER_RUNNING-true}
    PATH="${fixture_dir}:${PATH}" \
    FAKE_DOCKER_STATE="${temporary_root}/docker.state" \
    FAKE_DOCKER_LOG="${log_file}" \
    FAKE_DOCKER_CONTEXT=production-rehearsal \
    FAKE_DOCKER_CONTEXT_ENDPOINT=ssh://rehearsal@example.test \
    FAKE_DOCKER_EXACT_LOADED=1 \
    FAKE_DOCKER_EXACT_IMAGE="${exact_image}" \
    FAKE_DOCKER_APP_CONTAINER='' \
    FAKE_DOCKER_CONTAINER_RUNNING="${test_container_running}" \
    FAKE_DOCKER_DATABASE_EXISTS="${test_database_exists}" \
    COMPOSE_ENV_FILE="${temporary_root}/prod.secrets.env" \
    RELEASE_ENV_FILE="${temporary_root}/release.env" \
    PRODUCTION_COMPOSE_MODEL="${temporary_root}/compose.yaml" \
    DEPLOY_TARGET="${test_target}" \
    DEPLOY_EXPECTED_DOCKER_CONTEXT=production-rehearsal \
    DEPLOY_STATE_ROOT="${temporary_root}/deployment-state" \
    DEPLOY_BACKUP_DIR="${backup_directory}" \
    RECOVERY_SOURCE_TARGET="${test_source_target}" \
    RECOVERY_OFF_HOST_COPY_REFERENCE="${test_copy}" \
    RECOVERY_NOMINATIM_STRATEGY="${test_nominatim}" \
    RECOVERY_OBSERVATION_SECONDS=600 \
        "${project_root}/docker/release/recovery-rehearsal-plan.sh"
}

: >"${log_file}"
plan_output=$(run_plan)
printf '%s\n' "${plan_output}" | grep -Fq 'Recovery rehearsal plan (read-only)'
printf '%s\n' "${plan_output}" | grep -Fq 'recovery_status=ready'
printf '%s\n' "${plan_output}" | grep -Fq 'source_target=moscow-primary'
printf '%s\n' "${plan_output}" | grep -Fq 'rehearsal_target=moscow-rehearsal'
printf '%s\n' "${plan_output}" | grep -Fq 'docker_context_endpoint=ssh://example.test'
printf '%s\n' "${plan_output}" | grep -Fq 'backup_gate=verified'
printf '%s\n' "${plan_output}" | grep -Fq 'database_state=database-absent'
printf '%s\n' "${plan_output}" | grep -Fq 'database_absence_gate=verified'
printf '%s\n' "${plan_output}" | grep -Fq 'off_host_copy_reference=vault-copy-20261109'
printf '%s\n' "${plan_output}" | grep -Fq 'nominatim_strategy=rebuild'
printf '%s\n' "${plan_output}" | grep -Fq 'observation_seconds=600'
printf '%s\n' "${plan_output}" \
    | grep -Fq 'rpo_measurement=backup-created-at-to-rehearsal-cutover'
printf '%s\n' "${plan_output}" \
    | grep -Fq 'rto_measurement=restore-start-to-bounded-smoke-pass'
printf '%s\n' "${plan_output}" | grep -Fq 'recovery_external_mutations=none'
if printf '%s\n' "${plan_output}" | grep -Fq 'test-database-password'; then
    echo 'Recovery rehearsal plan test failed: a secret reached plan output.' >&2
    exit 1
fi
contract_assert_docker_log_read_only "${log_file}" 'recovery rehearsal plan'

if same_target_output=$(TEST_RECOVERY_SOURCE_TARGET=moscow-rehearsal \
    run_plan 2>&1); then
    echo 'Recovery rehearsal plan test failed: source target was reused.' >&2
    exit 1
fi
printf '%s\n' "${same_target_output}" \
    | grep -Fq 'recovery rehearsal target must differ'

if copy_output=$(TEST_OFF_HOST_COPY='' run_plan 2>&1); then
    echo 'Recovery rehearsal plan test failed: missing off-host copy was accepted.' >&2
    exit 1
fi
printf '%s\n' "${copy_output}" \
    | grep -Fq 'RECOVERY_OFF_HOST_COPY_REFERENCE must be a non-secret evidence label'

if nominatim_output=$(TEST_NOMINATIM_STRATEGY=skip run_plan 2>&1); then
    echo 'Recovery rehearsal plan test failed: Nominatim gate was silently skipped.' >&2
    exit 1
fi
printf '%s\n' "${nominatim_output}" \
    | grep -Fq 'RECOVERY_NOMINATIM_STRATEGY must be restore or rebuild'

if database_output=$(TEST_DATABASE_EXISTS=1 run_plan 2>&1); then
    echo 'Recovery rehearsal plan test failed: non-empty target database was accepted.' >&2
    exit 1
fi
printf '%s\n' "${database_output}" \
    | grep -Fq 'recovery rehearsal target database is not empty'

deferred_output=$(TEST_CONTAINER_RUNNING=false run_plan)
printf '%s\n' "${deferred_output}" \
    | grep -Fq 'database_state=postgres-not-running'
printf '%s\n' "${deferred_output}" \
    | grep -Fq 'database_absence_gate=deferred-to-restore-preflight'

echo 'Recovery rehearsal read-only plan contract test passed.'
