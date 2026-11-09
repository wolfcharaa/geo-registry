#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
default_project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)
project_root=${RELEASE_PROJECT_ROOT:-${default_project_root}}

# shellcheck disable=SC1091
. "${script_dir}/release-lib.sh"

for command_name in awk basename cp docker git grep ln mkdir mktemp mv tar tr wc; do
    release_require_command "${command_name}"
done

if [ "$(id -u)" -eq 0 ] || [ -n "${SUDO_USER:-}" ]; then
    release_fail "run as the release user, without sudo"
fi

source_env_file=$(release_resolve_path "${RELEASE_ENV_FILE:-${RELEASE_SOURCE_ENV_FILE:-docker/compose/prod/release.env}}")
release_require_file "${source_env_file}"
compose_file=$(release_resolve_path "${RELEASE_COMPOSE_FILE:-docker/compose/prod/compose.yaml}")
release_require_file "${compose_file}"
quick_guide_path="${project_root}/docs/deployment/offline-release.md"
deploy_guide_path="${project_root}/docs/deployment/release-deploy.md"
release_require_file "${quick_guide_path}"
release_require_file "${deploy_guide_path}"
release_version=$(release_env_value RELEASE_VERSION "${source_env_file}" || true)
release_validate_version "${release_version}"
platform=${DOCKER_PLATFORM:-linux/amd64}
release_validate_platform "${platform}"
platform_slug=$(printf '%s' "${platform}" | tr / -)

[ -z "$(git -C "${project_root}" status --porcelain)" ] \
    || release_fail "repository must be clean before building an offline archive"
release_commit=$(git -C "${project_root}" rev-parse HEAD)
tag_commit=$(git -C "${project_root}" rev-parse "refs/tags/${release_version}^{commit}" 2>/dev/null || true)
release_validate_git_oid "${release_commit}"
release_validate_git_oid "${tag_commit}"
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
mkdir -p "${artifact_dir}"
release_acquire_output_lock \
    "${artifact_dir}/.release-${platform_slug}.lock" \
    build-offline-archive "${release_version}" "${platform}"

archive_name="source-${platform_slug}.tar"
archive_path="${artifact_dir}/${archive_name}"
manifest_path="${artifact_dir}/source-manifest.txt"
phase_manifest_path="${artifact_dir}/phase-manifest.txt"
outer_path=$(release_resolve_path "${OFFLINE_ARCHIVE_PATH:-release-geo-registry-${release_version}-offline.tar}")
checksum_path="${outer_path}.sha256"

include_application_data=${RELEASE_INCLUDE_APPLICATION_DATA:-0}
case "${include_application_data}" in
    0|1) ;;
    *) release_fail "RELEASE_INCLUDE_APPLICATION_DATA must be 0 or 1" ;;
esac
case "${include_application_data}" in
    1) requested_data_bundle=application-postgres ;;
    *) requested_data_bundle=none ;;
esac

config_revision=$(release_config_revision \
    "${compose_file}" "${source_env_file}")
source_identity_format=$(release_source_identity_format "${source_env_file}")
identity_file=$(release_identity_file "${source_env_file}" "${artifact_dir}")
if [ "${source_identity_format}" = 2 ]; then
    release_validate_prepared_source_files "${source_env_file}" "${compose_file}"
    manifest_config_revision=${config_revision}
    release_validate_artifact_identity \
        "${identity_file}" "${release_version}" "${platform}" \
        "${release_commit}" "${config_revision}"
    artifact_identity_sha256=$(release_sha256 "${identity_file}")
else
    manifest_config_revision=${release_commit}
    artifact_identity_sha256=legacy-tracked-release-env
fi
if [ -f "${phase_manifest_path}" ]; then
    phase_manifest_format=$(release_manifest_value phase_manifest_format "${phase_manifest_path}" || true)
    release_validate_phase_manifest_format "${phase_manifest_format}"
    if [ "${phase_manifest_format}" = 2 ]; then
        release_phase_manifest_expect "${phase_manifest_path}" release_version "${release_version}"
        release_phase_manifest_expect "${phase_manifest_path}" platform "${platform}"
        if [ "$(release_manifest_value offline_payload_state "${phase_manifest_path}" || true)" = verified ]; then
            release_phase_manifest_expect "${phase_manifest_path}" offline_payload_release_oid "${release_commit}"
            release_phase_manifest_expect "${phase_manifest_path}" offline_payload_config_revision "${config_revision}"
            release_phase_manifest_expect "${phase_manifest_path}" offline_payload_archive "${archive_name}"
        fi
        if [ "$(release_manifest_value offline_export_state "${phase_manifest_path}" || true)" = verified ]; then
            release_phase_manifest_expect "${phase_manifest_path}" offline_export_release_oid "${release_commit}"
            release_phase_manifest_expect "${phase_manifest_path}" offline_export_config_revision "${config_revision}"
            release_phase_manifest_expect "${phase_manifest_path}" offline_export_data_bundle "${requested_data_bundle}"
            release_phase_manifest_expect "${phase_manifest_path}" offline_export_artifact_identity_sha256 "${artifact_identity_sha256}"
            release_phase_manifest_expect "${phase_manifest_path}" offline_export_path "${outer_path}"
            release_phase_manifest_expect "${phase_manifest_path}" offline_export_checksum_path "${checksum_path}"
            release_require_file "${outer_path}"
            release_require_file "${checksum_path}"
            expected_outer_size=$(release_manifest_value offline_export_size "${phase_manifest_path}" || true)
            expected_outer_sha256=$(release_manifest_value offline_export_sha256 "${phase_manifest_path}" || true)
            release_validate_size "${expected_outer_size}"
            [ "$(release_file_size "${outer_path}")" = "${expected_outer_size}" ] \
                || release_fail "verified offline transport size changed; refusing an implicit rebuild"
            [ "$(release_sha256 "${outer_path}")" = "${expected_outer_sha256}" ] \
                || release_fail "verified offline transport checksum changed; refusing an implicit rebuild"
            [ "$(awk '{print $1; exit}' "${checksum_path}")" = "${expected_outer_sha256}" ] \
                || release_fail "verified offline checksum sidecar changed"

            echo "Reusing verified offline release archive: ${outer_path}"
            echo "SHA-256: ${expected_outer_sha256}"
            echo "Checksum file: ${checksum_path}"
            exit 0
        fi
    fi
fi

[ ! -e "${outer_path}" ] || release_fail "offline archive already exists: ${outer_path}"
[ ! -e "${checksum_path}" ] || release_fail "offline checksum already exists: ${checksum_path}"
release_require_file "${archive_path}"
release_require_file "${manifest_path}"
manifest_format=$(release_manifest_value manifest_format "${manifest_path}" || true)
release_validate_manifest_format "${manifest_format}"
[ "$(release_manifest_value release_version "${manifest_path}" || true)" = "${release_version}" ] \
    || release_fail "manifest release version does not match"
[ "$(release_manifest_value git_commit "${manifest_path}" || true)" = "${release_commit}" ] \
    || release_fail "manifest Git commit does not match current checkout"
[ "$(release_manifest_value platform "${manifest_path}" || true)" = "${platform}" ] \
    || release_fail "manifest platform does not match"
[ "$(release_manifest_value archive "${manifest_path}" || true)" = "${archive_name}" ] \
    || release_fail "manifest archive name does not match"
if [ "${manifest_format}" = 3 ]; then
    [ "$(release_manifest_value release_tag "${manifest_path}" || true)" = "${release_version}" ] \
        || release_fail "manifest release tag does not match"
    [ "$(release_manifest_value config_revision "${manifest_path}" || true)" = "${manifest_config_revision}" ] \
        || release_fail "manifest config revision does not match prepared source"
    [ "$(release_manifest_value image_count "${manifest_path}" || true)" = 1 ] \
        || release_fail "manifest image count must be one"
    [ "$(release_manifest_value image_1_reference "${manifest_path}" || true)" = \
        "$(release_manifest_value image_tag "${manifest_path}" || true)" ] \
        || release_fail "manifest image reference fields do not match"
    [ "$(release_manifest_value image_1_digest "${manifest_path}" || true)" = \
        "$(release_manifest_value image_digest "${manifest_path}" || true)" ] \
        || release_fail "manifest image digest fields do not match"
    release_validate_image_id \
        "$(release_manifest_value image_1_id "${manifest_path}" || true)"
    [ "$(release_manifest_value assurance "${manifest_path}" || true)" = integrity-only ] \
        || release_fail "manifest assurance must be integrity-only"
    if [ "${source_identity_format}" = 2 ]; then
        [ "$(release_env_value APP_IMAGE_TAG "${identity_file}" || true)" = \
            "${release_version}-${platform_slug}" ] \
            || release_fail "artifact identity image tag does not match release"
        [ "$(release_manifest_value image_1_reference "${manifest_path}" || true)" = \
            "$(release_env_value DOCKER_IMAGE_PREFIX "${identity_file}" || true)/app:$(release_env_value APP_IMAGE_TAG "${identity_file}" || true)" ] \
            || release_fail "artifact identity image reference does not match manifest"
        [ "$(release_manifest_value image_1_digest "${manifest_path}" || true)" = \
            "$(release_env_value DOCKER_IMAGE_PREFIX "${identity_file}" || true)/app@$(release_env_value APP_IMAGE_DIGEST "${identity_file}" || true)" ] \
            || release_fail "artifact identity image digest does not match manifest"
        [ "$(release_manifest_value image_1_id "${manifest_path}" || true)" = \
            "$(release_env_value APP_IMAGE_ID "${identity_file}" || true)" ] \
            || release_fail "artifact identity image ID does not match manifest"
    fi
    expected_archive_size=$(release_manifest_value archive_size "${manifest_path}" || true)
    release_validate_size "${expected_archive_size}"
    [ "$(release_file_size "${archive_path}")" = "${expected_archive_size}" ] \
        || release_fail "image archive size does not match manifest"
fi
expected_archive_sha256=$(release_manifest_value archive_sha256 "${manifest_path}" || true)
[ "$(release_sha256 "${archive_path}")" = "${expected_archive_sha256}" ] \
    || release_fail "image archive checksum does not match before transport packaging"

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
if [ "${source_identity_format}" = 2 ]; then
    ln "${identity_file}" "${stage_dir}/release-artifact.env"
fi
cp "${quick_guide_path}" "${stage_dir}/RELEASE.md"
cp "${deploy_guide_path}" "${stage_dir}/DEPLOY.md"

if [ "${include_application_data}" = 1 ]; then
    ln "${data_archive_path}" "${stage_dir}/${data_archive_name}"
fi

set -- "${bundle_name}" "${archive_name}"
if [ "${include_application_data}" = 1 ]; then
    set -- "$@" "${data_archive_name}"
fi
set -- "$@" source-manifest.txt
if [ "${source_identity_format}" = 2 ]; then
    set -- "$@" release-artifact.env
fi
set -- "$@" RELEASE.md DEPLOY.md
tar -cf "${outer_temporary}" -C "${stage_dir}" "$@"

actual_members=$(tar -tf "${outer_temporary}")
expected_members=$(printf '%s\n' "$@")
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

outer_size=$(release_file_size "${outer_path}")
release_phase_manifest_update "${phase_manifest_path}" \
    phase_manifest_format 2 \
    phase offline-exported \
    release_version "${release_version}" \
    platform "${platform}" \
    release_oid "${release_commit}" \
    release_config_revision "${config_revision}" \
    offline_export_state verified \
    offline_export_release_oid "${release_commit}" \
    offline_export_config_revision "${config_revision}" \
    offline_export_data_bundle "${requested_data_bundle}" \
    offline_export_path "${outer_path}" \
    offline_export_size "${outer_size}" \
    offline_export_sha256 "${outer_sha256}" \
    offline_export_checksum_path "${checksum_path}" \
    offline_export_bundle "${bundle_name}" \
    offline_export_bundle_sha256 "${bundle_sha256}" \
    offline_export_manifest_sha256 "$(release_sha256 "${manifest_path}")" \
    offline_export_artifact_identity_sha256 "${artifact_identity_sha256}" \
    offline_export_verified_at "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" \
    offline_export_verified_by "$(id -un)"

echo "Offline release archive created: ${outer_path}"
echo "SHA-256: ${outer_sha256}"
echo "Checksum file: ${checksum_path}"
