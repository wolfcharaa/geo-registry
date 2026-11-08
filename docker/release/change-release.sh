#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)

# shellcheck disable=SC1091
. "${script_dir}/release-lib.sh"

for command_name in awk docker git grep tr; do
    release_require_command "${command_name}"
done

if [ "$(id -u)" -eq 0 ] || [ -n "${SUDO_USER:-}" ]; then
    release_fail "run as the deployment user, without sudo"
fi

app_env_file=$(release_resolve_path "${COMPOSE_ENV_FILE:-config/prod.env}")
release_env_file=$(release_resolve_path "${RELEASE_ENV_FILE:-docker/compose/prod/release.env}")
release_require_file "${app_env_file}"
release_require_file "${release_env_file}"

release_version=$(release_env_value RELEASE_VERSION "${release_env_file}" || true)
image_tag=$(release_env_value APP_IMAGE_TAG "${release_env_file}" || true)
image_digest=$(release_env_value APP_IMAGE_DIGEST "${release_env_file}" || true)
image_prefix=$(release_env_value DOCKER_IMAGE_PREFIX "${app_env_file}" || true)
platform=${DOCKER_PLATFORM:-linux/amd64}

release_validate_version "${release_version}"
release_validate_digest "${image_digest}"
release_validate_prefix "${image_prefix}"
release_validate_platform "${platform}"

platform_slug=$(printf '%s' "${platform}" | tr / -)
expected_tag="${release_version}-${platform_slug}"
[ "${image_tag}" = "${expected_tag}" ] \
    || release_fail "APP_IMAGE_TAG is ${image_tag}, expected ${expected_tag}"

tagged_image="${image_prefix}/app:${image_tag}"
exact_image="${image_prefix}/app@${image_digest}"
artifact_dir=$(release_resolve_path "${RELEASE_ARTIFACT_DIR:-docker/data/release/${release_version}}")
archive_name="source-${platform_slug}.tar"
archive_path="${artifact_dir}/${archive_name}"
manifest_path="${artifact_dir}/source-manifest.txt"

if release_registry_image_is_loaded "${tagged_image}" "${exact_image}"; then
    echo "Frozen registry digest is already loaded; archive verification and docker load are skipped."
    release_validate_registry_image \
        "${tagged_image}" "${exact_image}" "${platform}" "${release_version}"
else
    release_require_file "${archive_path}"
    release_require_file "${manifest_path}"

    release_validate_manifest_format \
        "$(release_manifest_value manifest_format "${manifest_path}" || true)"
    [ "$(release_manifest_value release_version "${manifest_path}" || true)" = "${release_version}" ] \
        || release_fail "manifest release version does not match"
    current_commit=$(git -C "${project_root}" rev-parse HEAD)
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

    expected_archive_sha256=$(release_manifest_value archive_sha256 "${manifest_path}" || true)
    actual_archive_sha256=$(release_sha256 "${archive_path}")
    [ "${actual_archive_sha256}" = "${expected_archive_sha256}" ] \
        || release_fail "offline image archive checksum does not match"

    echo "Loading verified image archive: ${archive_path}"
    docker load --input "${archive_path}"

    release_validate_runtime_image \
        "${tagged_image}" "${platform}" "${release_version}"
    echo "Offline image tag is bound to the verified archive checksum; a registry digest association is not required."
fi

COMPOSE_ENV_FILE="${app_env_file}" \
    RELEASE_ENV_FILE="${release_env_file}" \
    "${project_root}/docker/compose/prod.sh" config --quiet

if [ "${RELEASE_DRY_RUN:-0}" = 1 ]; then
    echo "Release ${release_version} verified; dry-run leaves containers unchanged."
    exit 0
fi

COMPOSE_ENV_FILE="${app_env_file}" \
    RELEASE_ENV_FILE="${release_env_file}" \
    "${project_root}/docker/compose/prod.sh" run --rm --no-deps app \
        bin/app message-bus:registry:check --no-interaction

COMPOSE_ENV_FILE="${app_env_file}" \
    RELEASE_ENV_FILE="${release_env_file}" \
    "${project_root}/docker/compose/prod.sh" up \
        --detach \
        --force-recreate \
        --remove-orphans \
        --wait \
        --wait-timeout "${RELEASE_WAIT_TIMEOUT_SECONDS:-120}" \
        app worker-address worker-runtime

echo "Release ${release_version} is active."
