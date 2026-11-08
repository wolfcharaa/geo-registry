#!/bin/sh
set -eu

fail() {
    echo "GAR download error: $*" >&2
    exit 1
}

for command_name in awk curl mkdir mv rm sha256sum unzip; do
    command -v "${command_name}" >/dev/null 2>&1 \
        || fail "required command is missing: ${command_name}"
done

source_url=${GAR_URL:-}
archive_path=${GAR_ARCHIVE:-/data/gar/gar-full.zip}
destination=${GAR_DESTINATION:-/data/gar/full}
expected_sha256=${GAR_SHA256:-}

case "${source_url}" in
    https://*) ;;
    *) fail "GAR_URL must be an explicit HTTPS full XML archive URL" ;;
esac
case "${archive_path}" in
    /data/gar/*.zip) ;;
    *) fail "GAR_ARCHIVE must be a .zip path below /data/gar" ;;
esac
case "${destination}" in
    /data/gar/*) ;;
    *) fail "GAR_DESTINATION must be below /data/gar" ;;
esac
[ ! -e "${destination}" ] || fail "destination already exists: ${destination}"

archive_directory=${archive_path%/*}
mkdir -p "${archive_directory}"
if [ ! -f "${archive_path}" ]; then
    temporary_archive="${archive_path}.partial.$$"
    cleanup_archive() {
        rm -f -- "${temporary_archive}"
    }
    trap cleanup_archive EXIT HUP INT TERM
    curl --fail --location --retry 3 --output "${temporary_archive}" "${source_url}"
    mv "${temporary_archive}" "${archive_path}"
    trap - EXIT HUP INT TERM
fi

actual_sha256=$(sha256sum "${archive_path}" | awk '{print $1}')
if [ -n "${expected_sha256}" ] && [ "${actual_sha256}" != "${expected_sha256}" ]; then
    fail "downloaded archive checksum does not match GAR_SHA256"
fi
unzip -tq "${archive_path}" >/dev/null

staging_directory="${destination}.partial.$$"
cleanup_staging() {
    case "${staging_directory}" in
        /data/gar/*.partial.*) rm -rf -- "${staging_directory}" ;;
    esac
}
trap cleanup_staging EXIT HUP INT TERM
mkdir -p "${staging_directory}"
unzip -q "${archive_path}" -d "${staging_directory}"
mv "${staging_directory}" "${destination}"
trap - EXIT HUP INT TERM

echo "GAR full XML archive downloaded and extracted: ${destination}"
echo "GAR archive SHA-256: ${actual_sha256}"
