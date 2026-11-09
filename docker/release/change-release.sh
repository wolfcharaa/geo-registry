#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
default_project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)
project_root=${RELEASE_PROJECT_ROOT:-${default_project_root}}

# shellcheck disable=SC1091
. "${script_dir}/release-lib.sh"
# shellcheck disable=SC1091
. "${script_dir}/deployment-lib.sh"

for command_name in awk docker git grep tr wc; do
    release_require_command "${command_name}"
done

if [ "$(id -u)" -eq 0 ] || [ -n "${SUDO_USER:-}" ]; then
    release_fail "run as the deployment user, without sudo"
fi

secrets_file=$(release_resolve_path "${COMPOSE_ENV_FILE:-config/prod.secrets.env}")
source_env_file=$(release_resolve_path "${RELEASE_ENV_FILE:-${RELEASE_SOURCE_ENV_FILE:-docker/compose/prod/release.env}}")
release_require_file "${secrets_file}"
release_require_file "${source_env_file}"
compose_file=$(release_resolve_path "${RELEASE_COMPOSE_FILE:-docker/compose/prod/compose.yaml}")
release_require_file "${compose_file}"

release_version=$(release_env_value RELEASE_VERSION "${source_env_file}" || true)
platform=${DOCKER_PLATFORM:-linux/amd64}

release_validate_version "${release_version}"
release_validate_platform "${platform}"

platform_slug=$(printf '%s' "${platform}" | tr / -)
artifact_dir=$(release_resolve_path "${RELEASE_ARTIFACT_DIR:-docker/data/release/${release_version}}")
identity_file=$(release_identity_file "${source_env_file}" "${artifact_dir}")
release_require_file "${identity_file}"
image_tag=$(release_env_value APP_IMAGE_TAG "${identity_file}" || true)
image_digest=$(release_env_value APP_IMAGE_DIGEST "${identity_file}" || true)
image_prefix=$(release_env_value DOCKER_IMAGE_PREFIX "${identity_file}" || true)
release_validate_digest "${image_digest}"
release_validate_prefix "${image_prefix}"
expected_tag="${release_version}-${platform_slug}"
[ "${image_tag}" = "${expected_tag}" ] \
    || release_fail "APP_IMAGE_TAG is ${image_tag}, expected ${expected_tag}"

tagged_image="${image_prefix}/app:${image_tag}"
exact_image="${image_prefix}/app@${image_digest}"
archive_name="source-${platform_slug}.tar"
archive_path="${artifact_dir}/${archive_name}"
manifest_path="${artifact_dir}/source-manifest.txt"
current_commit=$(git -C "${project_root}" rev-parse HEAD)
release_validate_git_oid "${current_commit}"
source_config_revision=$(release_config_revision \
    "${compose_file}" "${source_env_file}")
source_identity_format=$(release_source_identity_format "${source_env_file}")
if [ "${source_identity_format}" = 2 ]; then
    release_validate_prepared_source_files "${source_env_file}" "${compose_file}"
fi
release_validate_artifact_identity \
    "${identity_file}" "${release_version}" "${platform}" \
    "${current_commit}" "${source_config_revision}"

deployment_require_confirmed_target \
    change-release application "${DEPLOY_DATA_MODE:-none}" "${identity_file}"
deployment_acquire_target_lock \
    change-release application "${DEPLOY_DATA_MODE:-none}" "${identity_file}"

if release_registry_image_is_loaded "${tagged_image}" "${exact_image}"; then
    echo "Frozen registry digest is already loaded; archive verification and docker load are skipped."
    release_validate_registry_image \
        "${tagged_image}" "${exact_image}" "${platform}" "${release_version}"
else
    release_require_file "${archive_path}"
    release_require_file "${manifest_path}"

    manifest_format=$(release_manifest_value manifest_format "${manifest_path}" || true)
    release_validate_manifest_format "${manifest_format}"
    [ "$(release_manifest_value release_version "${manifest_path}" || true)" = "${release_version}" ] \
        || release_fail "manifest release version does not match"
    [ "$(release_manifest_value git_commit "${manifest_path}" || true)" = "${current_commit}" ] \
        || release_fail "manifest Git commit does not match current checkout"
    [ "$(release_manifest_value platform "${manifest_path}" || true)" = "${platform}" ] \
        || release_fail "manifest platform does not match"
    [ "$(release_manifest_value archive "${manifest_path}" || true)" = "${archive_name}" ] \
        || release_fail "manifest archive name does not match"
    [ "$(release_manifest_value image_tag "${manifest_path}" || true)" = "${tagged_image}" ] \
        || release_fail "manifest image tag does not match"
    [ "$(release_manifest_value image_digest "${manifest_path}" || true)" = "${exact_image}" ] \
        || release_fail "manifest image digest does not match"

    expected_image_id=
    case "${manifest_format}" in
        1)
            expected_image_id=$(release_manifest_value image_id "${manifest_path}" || true)
            ;;
        3)
            [ "$(release_manifest_value release_tag "${manifest_path}" || true)" = "${release_version}" ] \
                || release_fail "manifest release tag does not match"
            if [ "${source_identity_format}" = 2 ]; then
                expected_config_revision=${source_config_revision}
            else
                expected_config_revision=${current_commit}
            fi
            [ "$(release_manifest_value config_revision "${manifest_path}" || true)" = "${expected_config_revision}" ] \
                || release_fail "manifest config revision does not match current checkout"
            [ "$(release_manifest_value image_count "${manifest_path}" || true)" = 1 ] \
                || release_fail "manifest image count must be one"
            [ "$(release_manifest_value image_1_reference "${manifest_path}" || true)" = "${tagged_image}" ] \
                || release_fail "manifest image reference does not match"
            [ "$(release_manifest_value image_1_digest "${manifest_path}" || true)" = "${exact_image}" ] \
                || release_fail "manifest exact image does not match"
            expected_image_id=$(release_manifest_value image_1_id "${manifest_path}" || true)
            [ "$(release_manifest_value assurance "${manifest_path}" || true)" = integrity-only ] \
                || release_fail "manifest assurance must be integrity-only"

            expected_archive_size=$(release_manifest_value archive_size "${manifest_path}" || true)
            release_validate_size "${expected_archive_size}"
            [ "$(release_file_size "${archive_path}")" = "${expected_archive_size}" ] \
                || release_fail "offline image archive size does not match"
            ;;
    esac
    if [ -n "${expected_image_id}" ]; then
        release_validate_image_id "${expected_image_id}"
    fi

    expected_archive_sha256=$(release_manifest_value archive_sha256 "${manifest_path}" || true)
    actual_archive_sha256=$(release_sha256 "${archive_path}")
    [ "${actual_archive_sha256}" = "${expected_archive_sha256}" ] \
        || release_fail "offline image archive checksum does not match"

    echo "Loading verified image archive: ${archive_path}"
    docker load --input "${archive_path}"

    release_validate_runtime_image \
        "${tagged_image}" "${platform}" "${release_version}"
    if [ -n "${expected_image_id}" ]; then
        actual_image_id=$(docker image inspect --format '{{.Id}}' "${tagged_image}")
        [ "${actual_image_id}" = "${expected_image_id}" ] \
            || release_fail "loaded image ID is ${actual_image_id}, expected ${expected_image_id}"
    fi
    echo "Offline image tag is bound to the verified archive checksum; a registry digest association is not required."
fi

actual_image_id=$(docker image inspect --format '{{.Id}}' "${tagged_image}")
release_validate_image_id "${actual_image_id}"
release_validate_artifact_identity \
    "${identity_file}" "${release_version}" "${platform}" \
    "${current_commit}" "${source_config_revision}" "${actual_image_id}"

COMPOSE_ENV_FILE="${secrets_file}" \
    RELEASE_ENV_FILE="${identity_file}" \
    "${project_root}/docker/compose/prod.sh" config --quiet

if [ "${RELEASE_DRY_RUN:-0}" = 1 ]; then
    echo "Release ${release_version} verified; dry-run leaves containers unchanged."
    exit 0
fi

echo "Checking the configured HTTP host port immediately before activation."
COMPOSE_ENV_FILE="${secrets_file}" \
    RELEASE_ENV_FILE="${identity_file}" \
    "${script_dir}/deployment-port-preflight.sh"

COMPOSE_ENV_FILE="${secrets_file}" \
    RELEASE_ENV_FILE="${identity_file}" \
    "${project_root}/docker/compose/prod.sh" run --rm --no-deps app \
        bin/app message-bus:registry:check --no-interaction

COMPOSE_ENV_FILE="${secrets_file}" \
    RELEASE_ENV_FILE="${identity_file}" \
    "${project_root}/docker/compose/prod.sh" up \
        --detach \
        --force-recreate \
        --wait \
        --wait-timeout "${RELEASE_WAIT_TIMEOUT_SECONDS:-120}" \
        app worker-address worker-runtime

echo "Release ${release_version} is active."
