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

log_file="${temporary_root}/docker.log"
backup_directory="${temporary_root}/backups/application-postgres-v0.2.0"
mkdir -p "${backup_directory}"
printf '%s\n' 'placeholder' >"${backup_directory}/manifest.txt"
printf '%s\n' 'placeholder' >"${backup_directory}/application-postgres.dump"
cat >"${temporary_root}/prod.secrets.env" <<EOF
GEO_REGISTRY_DATABASE_PASSWORD=test-database-password
GEO_REGISTRY_POSTGRES_ADMIN_PASSWORD=test-admin-password
EOF

active_output=$(
    PATH="${fixture_dir}:${PATH}" \
    FAKE_DOCKER_STATE="${temporary_root}/docker.state" \
    FAKE_DOCKER_LOG="${log_file}" \
    FAKE_DOCKER_ACTIVE_RESTORES=1 \
    COMPOSE_ENV_FILE="${temporary_root}/prod.secrets.env" \
        "${project_root}/docker/release/deployment-data-status.sh"
)
printf '%s\n' "${active_output}" | grep -Fq 'State: restore-active'
printf '%s\n' "${active_output}" | grep -Fq 'Next action: wait; do not rerun restore or stop PostgreSQL'

if PATH="${fixture_dir}:${PATH}" \
    FAKE_DOCKER_STATE="${temporary_root}/docker.state" \
    FAKE_DOCKER_ACTIVE_RESTORES=1 \
    DEPLOY_DATA_REQUIRE_READY=1 \
    COMPOSE_ENV_FILE="${temporary_root}/prod.secrets.env" \
        "${project_root}/docker/release/deployment-data-status.sh" >/dev/null 2>&1; then
    echo 'Deployment data status test failed: active restore passed the readiness gate.' >&2
    exit 1
fi

complete_output=$(
    PATH="${fixture_dir}:${PATH}" \
    FAKE_DOCKER_STATE="${temporary_root}/docker.state" \
    FAKE_DOCKER_ACTIVE_RESTORES=0 \
    COMPOSE_ENV_FILE="${temporary_root}/prod.secrets.env" \
        "${project_root}/docker/release/deployment-data-status.sh"
)
printf '%s\n' "${complete_output}" | grep -Fq 'Size: 77 GB'
printf '%s\n' "${complete_output}" | grep -Fq 'Invalid indexes: 0'
printf '%s\n' "${complete_output}" | grep -Fq 'State: present-needs-application-validation'
printf '%s\n' "${complete_output}" | grep -Fq 'Next action: make deploy-start'

if invalid_output=$(
    PATH="${fixture_dir}:${PATH}" \
    FAKE_DOCKER_STATE="${temporary_root}/docker.state" \
    FAKE_DOCKER_INTEGRITY_SUMMARY='83|1|0' \
    COMPOSE_ENV_FILE="${temporary_root}/prod.secrets.env" \
        "${project_root}/docker/release/deployment-data-status.sh" 2>&1
); then
    echo 'Deployment data status test failed: invalid restore passed its gate.' >&2
    exit 1
fi
printf '%s\n' "${invalid_output}" | grep -Fq 'State: present-incomplete-or-invalid'

if elevated_output=$(
    PATH="${fixture_dir}:${PATH}" \
    FAKE_DOCKER_STATE="${temporary_root}/docker.state" \
    FAKE_DOCKER_ROLE_SUMMARY='1|1|1|0' \
    COMPOSE_ENV_FILE="${temporary_root}/prod.secrets.env" \
        "${project_root}/docker/release/deployment-data-status.sh" 2>&1
); then
    echo 'Deployment data status test failed: elevated restore owner passed its gate.' >&2
    exit 1
fi
printf '%s\n' "${elevated_output}" | grep -Fq 'State: owner-security-gate-failed'

if retry_output=$(
    PATH="${fixture_dir}:${PATH}" \
    FAKE_DOCKER_STATE="${temporary_root}/docker.state" \
    FAKE_DOCKER_LOG="${log_file}" \
    FAKE_DOCKER_ACTIVE_RESTORES=1 \
    COMPOSE_ENV_FILE="${temporary_root}/prod.secrets.env" \
        "${project_root}/docker/release/restore-deployment-data.sh" \
        --yes --backup-root="${temporary_root}/backups" 2>&1
); then
    echo 'Deployment data status test failed: restore retry unexpectedly succeeded.' >&2
    exit 1
fi
printf '%s\n' "${retry_output}" | grep -Fq 'restore is already active for geo_registry; run make data-restore-status and wait instead of retrying'
if grep -Fq 'ALTER ROLE geo_registry' "${log_file}"; then
    echo 'Deployment data status test failed: retry elevated the owner role.' >&2
    exit 1
fi

echo 'Deployment data status and restore re-entry contract test passed.'
