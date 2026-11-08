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

artifact_dir="${temporary_root}/release/v0.2.0"
mkdir -p "${artifact_dir}"
archive_path="${artifact_dir}/source-linux-amd64.tar"
printf '%s\n' 'verified offline payload fixture' >"${archive_path}"
archive_sha256=$(sha256sum "${archive_path}" | awk '{print $1}')
release_commit=$(git -C "${project_root}" rev-parse HEAD)
digest=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
tagged_image=ghcr.io/wolfcharaa/geo-registry/app:v0.2.0-linux-amd64
exact_image="ghcr.io/wolfcharaa/geo-registry/app@sha256:${digest}"

cat >"${temporary_root}/prod.env" <<EOF
DOCKER_IMAGE_PREFIX=ghcr.io/wolfcharaa/geo-registry
EOF

cat >"${temporary_root}/release.env" <<EOF
APP_IMAGE_TAG=v0.2.0-linux-amd64
APP_IMAGE_DIGEST=sha256:${digest}
RELEASE_VERSION=v0.2.0
COMPOSE_PROJECT_NAME=geo-registry
EOF

state_file="${temporary_root}/docker-load.state"
for manifest_format in 1 2; do
    {
        printf 'manifest_format=%s\n' "${manifest_format}"
        printf '%s\n' 'release_version=v0.2.0'
        printf 'git_commit=%s\n' "${release_commit}"
        printf '%s\n' 'platform=linux/amd64'
        printf '%s\n' 'archive=source-linux-amd64.tar'
        printf 'archive_sha256=%s\n' "${archive_sha256}"
        printf 'image_tag=%s\n' "${tagged_image}"
        printf 'image_digest=%s\n' "${exact_image}"
        if [ "${manifest_format}" = 1 ]; then
            printf 'image_id=sha256:%s\n' "${digest}"
        fi
    } >"${artifact_dir}/source-manifest.txt"

    rm -f "${state_file}"
    output=$(
        PATH="${fixture_dir}:${PATH}" \
        FAKE_DOCKER_STATE="${state_file}" \
        COMPOSE_ENV_FILE="${temporary_root}/prod.env" \
        RELEASE_ENV_FILE="${temporary_root}/release.env" \
        RELEASE_ARTIFACT_DIR="${artifact_dir}" \
        RELEASE_DRY_RUN=1 \
        DOCKER_PLATFORM=linux/amd64 \
            "${project_root}/docker/release/change-release.sh"
    )

    [ -f "${state_file}" ] || {
        echo "Offline release contract test failed for manifest v${manifest_format}: docker load was not called." >&2
        exit 1
    }
    printf '%s\n' "${output}" | grep -Fq 'Offline image tag is bound to the verified archive checksum'
    printf '%s\n' "${output}" | grep -Fq 'Release v0.2.0 verified; dry-run leaves containers unchanged.'
done

grep -Fq "/app:\${APP_IMAGE_TAG:" "${project_root}/docker/compose/prod/compose.yaml"
if grep -Fq "/app@\${APP_IMAGE_DIGEST:" "${project_root}/docker/compose/prod/compose.yaml"; then
    echo 'Offline release contract test failed: production Compose still requires a registry digest reference.' >&2
    exit 1
fi

echo 'Offline release contract test passed.'
