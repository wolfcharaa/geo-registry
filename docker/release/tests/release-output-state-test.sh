#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH='' cd -- "${script_dir}/../../.." && pwd)
temporary_root=$(mktemp -d)

# shellcheck disable=SC1091
. "${project_root}/docker/release/release-lib.sh"

cleanup() {
    rm -rf -- "${temporary_root}"
}
trap cleanup EXIT HUP INT TERM

artifact_root="${temporary_root}/release"
candidate_dir="${artifact_root}/v9.9.8"
current_dir="${artifact_root}/v9.9.9"
mkdir -p "${candidate_dir}" "${current_dir}"
lock_file="${candidate_dir}/.release-linux-amd64.lock"

exec 8>>"${lock_file}"
flock -n 8
: >"${lock_file}"
printf '%s\n' 'operation=held-by-test' >&8

if lock_output=$(
    sh -c '
        project_root=$1
        . "$project_root/docker/release/release-lib.sh"
        release_acquire_output_lock "$2" competing v9.9.8 linux/amd64
    ' sh "${project_root}" "${lock_file}" 2>&1
); then
    echo 'Release output state test failed: concurrent lock was acquired.' >&2
    exit 1
fi
printf '%s\n' "${lock_output}" | grep -Fq 'operation=held-by-test'
printf '%s\n' "${lock_output}" | grep -Fq 'another release operation owns v9.9.8'

flock -u 8
sh -c '
    project_root=$1
    . "$project_root/docker/release/release-lib.sh"
    release_acquire_output_lock "$2" retry v9.9.8 linux/amd64
' sh "${project_root}" "${lock_file}"
grep -Fq 'operation=retry' "${lock_file}"

printf '%s\n' 'manifest_format=3' >"${candidate_dir}/source-manifest.txt"
phase_manifest="${current_dir}/phase-manifest.txt"
release_phase_manifest_update "${phase_manifest}" \
    phase_manifest_format 2 \
    phase built \
    release_version v9.9.9 \
    platform linux/amd64 \
    built_state verified \
    built_source_oid 1111111111111111111111111111111111111111
release_phase_manifest_update "${phase_manifest}" \
    phase_manifest_format 2 \
    phase registry-published \
    release_version v9.9.9 \
    platform linux/amd64 \
    registry_state verified \
    registry_release_oid 2222222222222222222222222222222222222222

release_phase_manifest_expect "${phase_manifest}" built_state verified
release_phase_manifest_expect "${phase_manifest}" \
    built_source_oid 1111111111111111111111111111111111111111
release_phase_manifest_expect "${phase_manifest}" registry_state verified
if mismatch_output=$(release_phase_manifest_expect "${phase_manifest}" platform linux/arm64 2>&1); then
    echo 'Release output state test failed: mismatched phase binding was accepted.' >&2
    exit 1
fi
printf '%s\n' "${mismatch_output}" | grep -Fq 'phase binding platform is linux/amd64, expected linux/arm64'

phase_manifest_snapshot="${current_dir}/phase-manifest-snapshot.txt"
cp "${phase_manifest}" "${phase_manifest_snapshot}"
if duplicate_update_output=$(release_phase_manifest_update "${phase_manifest}" \
    phase duplicate phase overwritten 2>&1); then
    echo 'Release output state test failed: duplicate update keys were accepted.' >&2
    exit 1
fi
printf '%s\n' "${duplicate_update_output}" \
    | grep -Fq 'phase manifest contains duplicate keys'
cmp "${phase_manifest_snapshot}" "${phase_manifest}"

newline_value=$(printf 'first-line\nsecond-line')
if newline_output=$(release_phase_manifest_update "${phase_manifest}" \
    phase "${newline_value}" 2>&1); then
    echo 'Release output state test failed: a multiline value was accepted.' >&2
    exit 1
fi
printf '%s\n' "${newline_output}" \
    | grep -Fq 'phase manifest values must stay on one line'
cmp "${phase_manifest_snapshot}" "${phase_manifest}"

if unknown_key_output=$(release_phase_manifest_update "${phase_manifest}" \
    unexpected_phase_field value 2>&1); then
    echo 'Release output state test failed: an unknown phase key was accepted.' >&2
    exit 1
fi
printf '%s\n' "${unknown_key_output}" \
    | grep -Fq 'unsupported phase manifest key: unexpected_phase_field'
cmp "${phase_manifest_snapshot}" "${phase_manifest}"

invalid_phase_manifest="${current_dir}/phase-manifest-invalid.txt"
cp "${phase_manifest}" "${invalid_phase_manifest}"
printf '%s\n' 'invalid-line' >>"${invalid_phase_manifest}"
if invalid_output=$(release_phase_manifest_update "${invalid_phase_manifest}" \
    phase blocked 2>&1); then
    echo 'Release output state test failed: an invalid persisted line was accepted.' >&2
    exit 1
fi
printf '%s\n' "${invalid_output}" \
    | grep -Fq 'phase manifest contains invalid key/value lines'
grep -Fxq 'invalid-line' "${invalid_phase_manifest}"

unknown_phase_manifest="${current_dir}/phase-manifest-unknown.txt"
cp "${phase_manifest}" "${unknown_phase_manifest}"
printf '%s\n' 'unexpected_phase_field=value' >>"${unknown_phase_manifest}"
if unknown_file_output=$(release_phase_manifest_update \
    "${unknown_phase_manifest}" phase blocked 2>&1); then
    echo 'Release output state test failed: an unknown persisted key was accepted.' >&2
    exit 1
fi
printf '%s\n' "${unknown_file_output}" \
    | grep -Fq 'phase manifest contains unsupported keys'
grep -Fxq 'unexpected_phase_field=value' "${unknown_phase_manifest}"

duplicate_phase_manifest="${current_dir}/phase-manifest-duplicate.txt"
cp "${phase_manifest}" "${duplicate_phase_manifest}"
printf '%s\n' 'phase=duplicate' >>"${duplicate_phase_manifest}"
if duplicate_file_output=$(release_phase_manifest_update \
    "${duplicate_phase_manifest}" phase blocked 2>&1); then
    echo 'Release output state test failed: duplicate persisted keys were accepted.' >&2
    exit 1
fi
printf '%s\n' "${duplicate_file_output}" \
    | grep -Fq 'phase manifest contains duplicate keys'
grep -Fc 'phase=' "${duplicate_phase_manifest}" | grep -Fxq 2

phase_manifest_link="${current_dir}/phase-manifest-link.txt"
ln -s "${phase_manifest}" "${phase_manifest_link}"
if link_output=$(release_phase_manifest_update "${phase_manifest_link}" \
    phase blocked 2>&1); then
    echo 'Release output state test failed: a symbolic-link state target was accepted.' >&2
    exit 1
fi
printf '%s\n' "${link_output}" \
    | grep -Fq 'phase manifest must not be a symbolic link'
cmp "${phase_manifest_snapshot}" "${phase_manifest}"

if find "${current_dir}" -maxdepth 1 -name '.phase-*.tmp.*' -print -quit | grep -q .; then
    echo 'Release output state test failed: atomic phase update left temporary files.' >&2
    exit 1
fi
touch -d '2 days ago' "${candidate_dir}" "${current_dir}"

cat >"${temporary_root}/release.env" <<'EOF'
RELEASE_VERSION=v9.9.9
EOF

preview_output=$(
    RELEASE_ARTIFACT_ROOT="${artifact_root}" \
    RELEASE_ENV_FILE="${temporary_root}/release.env" \
    RELEASE_CANDIDATE_TTL_DAYS=0 \
        "${project_root}/docker/release/release-candidates.sh"
)
printf '%s\n' "${preview_output}" | grep -Fq 'v9.9.8'
printf '%s\n' "${preview_output}" | grep -Fq 'v9.9.9'
printf '%s\n' "${preview_output}" | grep -Eq 'v9\.9\.8[[:space:]]+offline-payload[[:space:]]+no[[:space:]]+yes[[:space:]]+idle'
printf '%s\n' "${preview_output}" | grep -Eq 'v9\.9\.9[[:space:]]+registry-published[[:space:]]+yes[[:space:]]+yes[[:space:]]+none'
printf '%s\n' "${preview_output}" | grep -Fq 'No files were removed.'
[ -f "${candidate_dir}/source-manifest.txt" ]
[ -f "${current_dir}/phase-manifest.txt" ]

echo 'Release output lock and candidate preview test passed.'
