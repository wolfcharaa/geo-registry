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

state_file="${temporary_root}/docker.state"
log_file="${temporary_root}/docker.log"
mkdir -p "${temporary_root}/backups"

cat >"${temporary_root}/prod.secrets.env" <<EOF
GEO_REGISTRY_DATABASE_PASSWORD=test-database-password
GEO_REGISTRY_POSTGRES_ADMIN_PASSWORD=test-admin-password
EOF

output=$(
    PATH="${fixture_dir}:${PATH}" \
    FAKE_DOCKER_STATE="${state_file}" \
    FAKE_DOCKER_LOG="${log_file}" \
    FAKE_DOCKER_DUMP_SLEEP_SECONDS=2 \
    COMPOSE_ENV_FILE="${temporary_root}/prod.secrets.env" \
    BACKUP_ROOT="${temporary_root}/backups" \
    BACKUP_PROGRESS_INTERVAL_SECONDS=1 \
    BACKUP_APPLICATION_REVISION=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa \
        "${project_root}/docker/release/create-deployment-backup.sh"
)

printf '%s\n' "${output}" | grep -Fq 'Creating transaction-consistent application-postgres dump...'
printf '%s\n' "${output}" | grep -Fq 'Backup progress: elapsed_seconds='
printf '%s\n' "${output}" | grep -Fq 'Dump stream completed; verifying pg_restore table of contents and calculating SHA-256.'
printf '%s\n' "${output}" | grep -Fq 'Backup created:'

backup_count=0
for manifest_path in "${temporary_root}"/backups/application-postgres-*/manifest.txt; do
    [ -f "${manifest_path}" ] || continue
    backup_count=$((backup_count + 1))
    grep -Fq 'format=geo-registry-postgres-backup-v1' "${manifest_path}"
done
[ "${backup_count}" -eq 1 ] || {
    echo "Backup progress test failed: expected one published backup, got ${backup_count}." >&2
    exit 1
}

echo 'Backup progress contract test passed.'
