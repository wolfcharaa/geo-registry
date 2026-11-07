#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)

# shellcheck disable=SC1091
. "${script_dir}/backup-lib.sh"

for command_name in awk docker sha256sum tr wc; do
    backup_require_command "${command_name}"
done

backup_directory=${BACKUP_DIR:-}
backup_validate_bundle "${backup_directory}"

manifest_path="${backup_directory}/manifest.txt"
component=$(backup_manifest_value component "${manifest_path}" || true)
backup_validate_component "${component}"
dump_file=$(backup_manifest_value dump_file "${manifest_path}")
dump_path="${backup_directory}/${dump_file}"

case "${component}" in
    application-postgres) service=postgres ;;
    nominatim) service=nominatim ;;
esac
container=${VERIFY_CONTAINER:-$(backup_resolve_compose_container "${project_root}" "${service}")}
backup_validate_container "${container}"

docker exec --interactive "${container}" pg_restore --list <"${dump_path}" >/dev/null

source_version_num=$(backup_manifest_value server_version_num "${manifest_path}" || true)
backup_validate_positive_integer server_version_num "${source_version_num}"
restore_major=$(docker exec "${container}" pg_restore --version | awk '{print $3}' | awk -F. '{print $1}')
backup_validate_positive_integer pg_restore_major "${restore_major}"
source_major=$((source_version_num / 10000))
[ "${restore_major}" -ge "${source_major}" ] \
    || backup_fail "pg_restore major ${restore_major} is older than source PostgreSQL major ${source_major}"

echo "Backup verified without changing database state."
echo "Component: ${component}"
echo "Created at: $(backup_manifest_value created_at "${manifest_path}")"
echo "Dump bytes: $(backup_manifest_value dump_bytes "${manifest_path}")"
echo "Dump SHA-256: $(backup_manifest_value dump_sha256 "${manifest_path}")"
