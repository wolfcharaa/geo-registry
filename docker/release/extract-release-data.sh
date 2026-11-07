#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)

# shellcheck disable=SC1091
. "${script_dir}/release-lib.sh"
# shellcheck disable=SC1091
. "${project_root}/docker/backup/backup-lib.sh"

for command_name in awk mkdir mktemp mv tar; do
    release_require_command "${command_name}"
done

if [ "$(id -u)" -eq 0 ] || [ -n "${SUDO_USER:-}" ]; then
    release_fail "run as the deployment user, without sudo"
fi

release_directory=${RELEASE_EXTRACT_DIR:-}
backup_root=${BACKUP_ROOT:-}
dry_run=${RELEASE_DATA_DRY_RUN:-0}
case "${dry_run}" in
    0|1) ;;
    *) release_fail "RELEASE_DATA_DRY_RUN must be 0 or 1" ;;
esac
backup_require_directory RELEASE_EXTRACT_DIR "${release_directory}"
backup_require_directory BACKUP_ROOT "${backup_root}"
[ -w "${backup_root}" ] || release_fail "BACKUP_ROOT is not writable: ${backup_root}"

manifest_path="${release_directory}/source-manifest.txt"
release_require_file "${manifest_path}"
[ "$(release_manifest_value data_bundle "${manifest_path}" || true)" = application-postgres ] \
    || release_fail "release does not contain an application PostgreSQL data bundle"
release_version=$(release_manifest_value release_version "${manifest_path}" || true)
release_validate_version "${release_version}"
data_archive_name=$(release_manifest_value data_archive "${manifest_path}" || true)
[ "${data_archive_name}" = application-postgres-backup.tar ] \
    || release_fail "release data archive name is invalid"
data_archive_path="${release_directory}/${data_archive_name}"
release_require_file "${data_archive_path}"
expected_archive_sha256=$(release_manifest_value data_archive_sha256 "${manifest_path}" || true)
[ "$(release_sha256 "${data_archive_path}")" = "${expected_archive_sha256}" ] \
    || release_fail "release data archive checksum does not match"

actual_members=$(tar -tf "${data_archive_path}")
expected_members=$(printf '%s\n' application-postgres.dump manifest.txt)
[ "${actual_members}" = "${expected_members}" ] \
    || release_fail "release data archive contains unexpected members"

staging_directory=$(mktemp -d "${backup_root}/.geo-registry-release-data.XXXXXX")
cleanup() {
    case "${staging_directory}" in
        "${backup_root}"/.geo-registry-release-data.*) rm -rf -- "${staging_directory}" ;;
    esac
}
trap cleanup EXIT HUP INT TERM
chmod 0700 "${staging_directory}"
tar -xf "${data_archive_path}" -C "${staging_directory}"
backup_validate_bundle "${staging_directory}"

release_commit=$(release_manifest_value git_commit "${manifest_path}" || true)
backup_revision=$(backup_manifest_value application_revision "${staging_directory}/manifest.txt" || true)
[ "${backup_revision}" = "${release_commit}" ] \
    || release_fail "release data backup revision does not match the release manifest"
[ "$(backup_manifest_value dump_sha256 "${staging_directory}/manifest.txt" || true)" = \
    "$(release_manifest_value data_dump_sha256 "${manifest_path}" || true)" ] \
    || release_fail "release data dump checksum does not match the outer manifest"

if [ "${dry_run}" = 1 ]; then
    echo "Release application PostgreSQL data bundle verified; dry-run publishes no backup directory."
    exit 0
fi

final_directory="${backup_root}/application-postgres-${release_version}"
[ ! -e "${final_directory}" ] \
    || release_fail "release backup destination already exists: ${final_directory}"
mv "${staging_directory}" "${final_directory}"
trap - EXIT HUP INT TERM

echo "Release application PostgreSQL backup extracted: ${final_directory}"
