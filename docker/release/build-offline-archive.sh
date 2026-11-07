#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)

# shellcheck disable=SC1091
. "${script_dir}/release-lib.sh"

for command_name in awk cp git grep ln mkdir mktemp mv tar tr; do
    release_require_command "${command_name}"
done

if [ "$(id -u)" -eq 0 ] || [ -n "${SUDO_USER:-}" ]; then
    release_fail "run as the release user, without sudo"
fi

release_env_file=$(release_resolve_path "${RELEASE_ENV_FILE:-docker/compose/prod/release.env}")
release_require_file "${release_env_file}"
release_version=$(release_env_value RELEASE_VERSION "${release_env_file}" || true)
release_validate_version "${release_version}"
platform=${DOCKER_PLATFORM:-linux/amd64}
release_validate_platform "${platform}"
platform_slug=$(printf '%s' "${platform}" | tr / -)

[ -z "$(git -C "${project_root}" status --porcelain)" ] \
    || release_fail "repository must be clean before building an offline archive"
release_commit=$(git -C "${project_root}" rev-parse HEAD)
tag_commit=$(git -C "${project_root}" rev-parse "refs/tags/${release_version}^{commit}" 2>/dev/null || true)
[ "${tag_commit}" = "${release_commit}" ] \
    || release_fail "annotated tag ${release_version} must point to the current commit"
[ "$(git -C "${project_root}" cat-file -t "refs/tags/${release_version}")" = tag ] \
    || release_fail "${release_version} must be an annotated tag"

branch=$(git -C "${project_root}" branch --show-current)
[ -n "${branch}" ] || release_fail "offline archive must be built from a branch checkout"

artifact_dir=$(release_resolve_path "${RELEASE_ARTIFACT_DIR:-docker/data/release/${release_version}}")
archive_name="source-${platform_slug}.tar"
archive_path="${artifact_dir}/${archive_name}"
manifest_path="${artifact_dir}/source-manifest.txt"
release_require_file "${archive_path}"
release_require_file "${manifest_path}"
[ "$(release_manifest_value manifest_format "${manifest_path}" || true)" = 1 ] \
    || release_fail "unsupported manifest format"
[ "$(release_manifest_value release_version "${manifest_path}" || true)" = "${release_version}" ] \
    || release_fail "manifest release version does not match"
[ "$(release_manifest_value git_commit "${manifest_path}" || true)" = "${release_commit}" ] \
    || release_fail "manifest Git commit does not match current checkout"
[ "$(release_manifest_value platform "${manifest_path}" || true)" = "${platform}" ] \
    || release_fail "manifest platform does not match"
[ "$(release_manifest_value archive "${manifest_path}" || true)" = "${archive_name}" ] \
    || release_fail "manifest archive name does not match"
expected_archive_sha256=$(release_manifest_value archive_sha256 "${manifest_path}" || true)
[ "$(release_sha256 "${archive_path}")" = "${expected_archive_sha256}" ] \
    || release_fail "image archive checksum does not match before transport packaging"

bundle_name="release-geo-registry-${release_version}.bundle"
bundle_path="${artifact_dir}/${bundle_name}"
outer_path=$(release_resolve_path "${OFFLINE_ARCHIVE_PATH:-release-geo-registry-${release_version}-offline.tar}")
stage_dir=$(mktemp -d "${artifact_dir}/.offline-stage.XXXXXX")
bundle_temporary=$(mktemp "${artifact_dir}/.${bundle_name}.tmp.XXXXXX")
manifest_temporary=$(mktemp "${artifact_dir}/.source-manifest.bundle.tmp.XXXXXX")
outer_temporary="${outer_path}.tmp"
verifier_dir=
cleanup() {
    rm -rf "${stage_dir}"
    if [ -n "${verifier_dir}" ]; then
        rm -rf "${verifier_dir}"
    fi
    rm -f "${bundle_temporary}" "${manifest_temporary}" "${outer_temporary}"
}
trap cleanup EXIT INT TERM

git -C "${project_root}" bundle create "${bundle_temporary}" \
    "refs/heads/${branch}" "refs/tags/${release_version}"

verifier_dir=$(mktemp -d "${artifact_dir}/.bundle-verifier.XXXXXX")
git init --bare --quiet "${verifier_dir}"
git -C "${verifier_dir}" bundle verify "${bundle_temporary}" >/dev/null
rm -rf "${verifier_dir}"
verifier_dir=

mv "${bundle_temporary}" "${bundle_path}"
bundle_sha256=$(release_sha256 "${bundle_path}")

awk '!/^git_bundle(_sha256)?=/' "${manifest_path}" > "${manifest_temporary}"
{
    printf 'git_bundle=%s\n' "${bundle_name}"
    printf 'git_bundle_sha256=%s\n' "${bundle_sha256}"
} >> "${manifest_temporary}"
mv "${manifest_temporary}" "${manifest_path}"

ln "${archive_path}" "${stage_dir}/${archive_name}"
ln "${manifest_path}" "${stage_dir}/source-manifest.txt"
ln "${bundle_path}" "${stage_dir}/${bundle_name}"
cp "${project_root}/docs/deployment/offline-release.md" "${stage_dir}/RELEASE.md"
cp "${project_root}/docs/deployment/production-compose.md" "${stage_dir}/DEPLOY.md"

tar -cf "${outer_temporary}" -C "${stage_dir}" \
    "${bundle_name}" \
    "${archive_name}" \
    source-manifest.txt \
    RELEASE.md \
    DEPLOY.md

actual_members=$(tar -tf "${outer_temporary}")
expected_members=$(printf '%s\n' \
    "${bundle_name}" \
    "${archive_name}" \
    source-manifest.txt \
    RELEASE.md \
    DEPLOY.md)
[ "${actual_members}" = "${expected_members}" ] \
    || release_fail "offline archive contains an unexpected member list"

mv "${outer_temporary}" "${outer_path}"
trap - EXIT INT TERM
rm -rf "${stage_dir}"

echo "Offline release archive created: ${outer_path}"
echo "SHA-256: $(release_sha256 "${outer_path}")"
