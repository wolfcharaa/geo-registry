#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)

# shellcheck disable=SC1091
. "${script_dir}/release-lib.sh"

for command_name in awk basename cp docker git grep ln mkdir mktemp mv tar tr; do
    release_require_command "${command_name}"
done

if [ "$(id -u)" -eq 0 ] || [ -n "${SUDO_USER:-}" ]; then
    release_fail "run as the release user, without sudo"
fi

release_env_file=$(release_resolve_path "${RELEASE_ENV_FILE:-docker/compose/prod/release.env}")
release_require_file "${release_env_file}"
quick_guide_path="${project_root}/docs/deployment/offline-release.md"
deploy_guide_path="${project_root}/docs/deployment/release-deploy.md"
release_require_file "${quick_guide_path}"
release_require_file "${deploy_guide_path}"
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
release_branch=${RELEASE_BRANCH:-main}
[ "${branch}" = "${release_branch}" ] \
    || release_fail "offline archive must be built from ${release_branch}, current branch is ${branch}"

artifact_dir=$(release_resolve_path "${RELEASE_ARTIFACT_DIR:-docker/data/release/${release_version}}")
archive_name="source-${platform_slug}.tar"
archive_path="${artifact_dir}/${archive_name}"
manifest_path="${artifact_dir}/source-manifest.txt"
outer_path=$(release_resolve_path "${OFFLINE_ARCHIVE_PATH:-release-geo-registry-${release_version}-offline.tar}")
checksum_path="${outer_path}.sha256"
[ ! -e "${outer_path}" ] || release_fail "offline archive already exists: ${outer_path}"
[ ! -e "${checksum_path}" ] || release_fail "offline checksum already exists: ${checksum_path}"
release_require_file "${archive_path}"
release_require_file "${manifest_path}"
release_validate_manifest_format \
    "$(release_manifest_value manifest_format "${manifest_path}" || true)"
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

include_application_data=${RELEASE_INCLUDE_APPLICATION_DATA:-0}
case "${include_application_data}" in
    0|1) ;;
    *) release_fail "RELEASE_INCLUDE_APPLICATION_DATA must be 0 or 1" ;;
esac

data_archive_name=application-postgres-backup.tar
data_archive_path="${artifact_dir}/${data_archive_name}"
data_archive_sha256=
data_dump_sha256=
data_created_at=
if [ "${include_application_data}" = 1 ]; then
    data_backup_directory=${RELEASE_DATA_BACKUP_DIR:-}
    if [ -z "${data_backup_directory}" ]; then
        data_backup_output=$(
            BACKUP_COMPONENT=application-postgres \
            BACKUP_ROOT="${artifact_dir}" \
            BACKUP_CONTAINER="${RELEASE_DATA_CONTAINER:-}" \
            BACKUP_RESERVE_GB="${RELEASE_DATA_RESERVE_GB:-10}" \
            BACKUP_APPLICATION_REVISION="${release_commit}" \
                "${project_root}/docker/backup/create-postgres-backup.sh"
        )
        printf '%s\n' "${data_backup_output}"
        data_backup_directory=$(printf '%s\n' "${data_backup_output}" | awk -F': ' '$1 == "Backup created" {print substr($0, index($0, ": ") + 2)}')
        [ -n "${data_backup_directory}" ] \
            || release_fail "application PostgreSQL backup path was not reported"
    else
        data_backup_directory=$(release_resolve_path "${data_backup_directory}")
    fi

    BACKUP_DIR="${data_backup_directory}" VERIFY_CONTAINER="${RELEASE_DATA_CONTAINER:-}" \
        "${project_root}/docker/backup/verify-postgres-backup.sh"
    data_manifest_path="${data_backup_directory}/manifest.txt"
    [ "$(release_manifest_value component "${data_manifest_path}" || true)" = application-postgres ] \
        || release_fail "release data backup must contain application-postgres"
    [ "$(release_manifest_value application_revision "${data_manifest_path}" || true)" = "${release_commit}" ] \
        || release_fail "release data backup revision does not match the release commit"
    data_dump_sha256=$(release_manifest_value dump_sha256 "${data_manifest_path}" || true)
    data_created_at=$(release_manifest_value created_at "${data_manifest_path}" || true)

    data_archive_temporary=$(mktemp "${artifact_dir}/.${data_archive_name}.tmp.XXXXXX")
    cleanup_data_archive() {
        rm -f "${data_archive_temporary}"
    }
    trap cleanup_data_archive EXIT INT TERM
    tar -cf "${data_archive_temporary}" -C "${data_backup_directory}" \
        application-postgres.dump manifest.txt
    data_members=$(tar -tf "${data_archive_temporary}")
    expected_data_members=$(printf '%s\n' application-postgres.dump manifest.txt)
    [ "${data_members}" = "${expected_data_members}" ] \
        || release_fail "application PostgreSQL backup archive contains unexpected members"
    data_archive_sha256=$(release_sha256 "${data_archive_temporary}")
    mv "${data_archive_temporary}" "${data_archive_path}"
    trap - EXIT INT TERM
fi

bundle_name="release-geo-registry-${release_version}.bundle"
bundle_path="${artifact_dir}/${bundle_name}"
stage_dir=$(mktemp -d "${artifact_dir}/.offline-stage.XXXXXX")
bundle_temporary=$(mktemp "${artifact_dir}/.${bundle_name}.tmp.XXXXXX")
manifest_temporary=$(mktemp "${artifact_dir}/.source-manifest.bundle.tmp.XXXXXX")
outer_temporary="${outer_path}.tmp"
checksum_temporary="${checksum_path}.tmp"
verifier_dir=
outer_published=0
cleanup() {
    rm -rf "${stage_dir}"
    if [ -n "${verifier_dir}" ]; then
        rm -rf "${verifier_dir}"
    fi
    rm -f "${bundle_temporary}" "${manifest_temporary}" "${outer_temporary}" "${checksum_temporary}"
    if [ "${outer_published}" = 1 ]; then
        rm -f "${outer_path}" "${checksum_path}"
    fi
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

awk -F= '$1 !~ /^(git_bundle|git_bundle_sha256|data_bundle|data_archive|data_archive_sha256|data_created_at|data_dump_sha256)$/' \
    "${manifest_path}" > "${manifest_temporary}"
{
    printf 'git_bundle=%s\n' "${bundle_name}"
    printf 'git_bundle_sha256=%s\n' "${bundle_sha256}"
    if [ "${include_application_data}" = 1 ]; then
        printf '%s\n' 'data_bundle=application-postgres'
        printf 'data_archive=%s\n' "${data_archive_name}"
        printf 'data_archive_sha256=%s\n' "${data_archive_sha256}"
        printf 'data_created_at=%s\n' "${data_created_at}"
        printf 'data_dump_sha256=%s\n' "${data_dump_sha256}"
    else
        printf '%s\n' 'data_bundle=none'
    fi
} >> "${manifest_temporary}"
mv "${manifest_temporary}" "${manifest_path}"

ln "${archive_path}" "${stage_dir}/${archive_name}"
ln "${manifest_path}" "${stage_dir}/source-manifest.txt"
ln "${bundle_path}" "${stage_dir}/${bundle_name}"
cp "${quick_guide_path}" "${stage_dir}/RELEASE.md"
cp "${deploy_guide_path}" "${stage_dir}/DEPLOY.md"

if [ "${include_application_data}" = 1 ]; then
    ln "${data_archive_path}" "${stage_dir}/${data_archive_name}"
fi

if [ "${include_application_data}" = 1 ]; then
    tar -cf "${outer_temporary}" -C "${stage_dir}" \
        "${bundle_name}" \
        "${archive_name}" \
        "${data_archive_name}" \
        source-manifest.txt \
        RELEASE.md \
        DEPLOY.md
else
    tar -cf "${outer_temporary}" -C "${stage_dir}" \
        "${bundle_name}" \
        "${archive_name}" \
        source-manifest.txt \
        RELEASE.md \
        DEPLOY.md
fi

actual_members=$(tar -tf "${outer_temporary}")
if [ "${include_application_data}" = 1 ]; then
    expected_members=$(printf '%s\n' \
        "${bundle_name}" \
        "${archive_name}" \
        "${data_archive_name}" \
        source-manifest.txt \
        RELEASE.md \
        DEPLOY.md)
else
    expected_members=$(printf '%s\n' \
        "${bundle_name}" \
        "${archive_name}" \
        source-manifest.txt \
        RELEASE.md \
        DEPLOY.md)
fi
[ "${actual_members}" = "${expected_members}" ] \
    || release_fail "offline archive contains an unexpected member list"

outer_sha256=$(release_sha256 "${outer_temporary}")
printf '%s  %s\n' "${outer_sha256}" "$(basename "${outer_path}")" > "${checksum_temporary}"
mv "${outer_temporary}" "${outer_path}"
outer_published=1
mv "${checksum_temporary}" "${checksum_path}"
outer_published=0
trap - EXIT INT TERM
rm -rf "${stage_dir}"

echo "Offline release archive created: ${outer_path}"
echo "SHA-256: ${outer_sha256}"
echo "Checksum file: ${checksum_path}"
