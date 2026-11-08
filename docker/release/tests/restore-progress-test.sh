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

backup_directory="${temporary_root}/application-postgres-v0.2.0"
database_state="${temporary_root}/database.state"
mkdir -p "${backup_directory}"
printf '%s\n' 'fake-pgdump-archive' >"${backup_directory}/application-postgres.dump"
dump_bytes=$(wc -c <"${backup_directory}/application-postgres.dump" | tr -d ' ')
dump_sha256=$(sha256sum "${backup_directory}/application-postgres.dump" | awk '{print $1}')

cat >"${backup_directory}/manifest.txt" <<EOF
format=geo-registry-postgres-backup-v1
created_at=2026-11-08T00:00:00Z
component=application-postgres
application_revision=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
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
dump_bytes=${dump_bytes}
dump_sha256=${dump_sha256}
provenance_name=canonical-gar-database
provenance_sha256=none
EOF

output=$(
    PATH="${fixture_dir}:${PATH}" \
    FAKE_DOCKER_STATE="${temporary_root}/docker.state" \
    FAKE_DOCKER_DATABASE_EXISTS=0 \
    FAKE_DOCKER_DATABASE_STATE="${database_state}" \
    FAKE_DOCKER_RESTORE_SLEEP_SECONDS=2 \
    RESTORE_PROGRESS_INTERVAL_SECONDS=1 \
    BACKUP_DIR="${backup_directory}" \
    RESTORE_CONTAINER=geo-registry-postgres-test \
    RESTORE_DATABASE=geo_registry \
    RESTORE_DATABASE_OWNER=geo_registry \
    RESTORE_ADMIN_USER=postgres \
    RESTORE_CONFIRM=create:geo-registry-postgres-test:geo_registry \
        "${project_root}/docker/backup/restore-postgres-shadow.sh"
)

printf '%s\n' "${output}" | grep -Fq 'Restore phase started: schema, table data, indexes and constraints.'
printf '%s\n' "${output}" | grep -Fq 'Restore progress: elapsed_seconds='
printf '%s\n' "${output}" | grep -Fq 'state=active wait=IO/DataFileRead'
printf '%s\n' "${output}" | grep -Fq 'Shadow restore completed: geo_registry'
[ -f "${database_state}" ] || {
    echo 'Restore progress test failed: target database was not created.' >&2
    exit 1
}

echo 'Restore progress contract test passed.'
