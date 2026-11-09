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

fake_bin="${temporary_root}/bin"
mkdir -p "${fake_bin}"
ln -s "${fixture_dir}/docker" "${fake_bin}/docker"

cat >"${fake_bin}/git" <<'EOF'
#!/bin/sh
set -eu

[ "${1:-}" = -C ] || exit 64
shift 2

case "${1:-}" in
    status)
        ;;
    branch)
        printf '%s\n' main
        ;;
    rev-parse)
        printf '%s\n' "${FAKE_GIT_RELEASE_COMMIT:?}"
        ;;
    cat-file)
        case "${2:-}" in
            -t) printf '%s\n' tag ;;
            -e) ;;
            *) exit 64 ;;
        esac
        ;;
    diff)
        printf '%s\n' docker/compose/prod/compose.yaml docker/compose/prod/release.env
        ;;
    *)
        exit 64
        ;;
esac
EOF
chmod +x "${fake_bin}/git"

digest=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
release_commit=1111111111111111111111111111111111111111
source_commit=2222222222222222222222222222222222222222
tagged_image=registry.example/geo-registry/app:v9.9.9-linux-amd64
exact_image="registry.example/geo-registry/app@sha256:${digest}"
state_file="${temporary_root}/docker.state"
log_file="${temporary_root}/docker.log"
release_env_file="${temporary_root}/release.env"

cat >"${release_env_file}" <<EOF
DOCKER_IMAGE_PREFIX=registry.example/geo-registry
APP_IMAGE_TAG=v9.9.9-linux-amd64
APP_IMAGE_DIGEST=sha256:${digest}
RELEASE_VERSION=v9.9.9
COMPOSE_PROJECT_NAME=geo-registry
EOF

run_publish() {
    artifact_dir=$1
    remote_digest=$2
    buildx_format_supported=${3:-1}

    PATH="${fake_bin}:${PATH}" \
    FAKE_DOCKER_STATE="${state_file}" \
    FAKE_DOCKER_LOG="${log_file}" \
    FAKE_DOCKER_EXACT_LOADED=1 \
    FAKE_DOCKER_EXACT_IMAGE="${exact_image}" \
    FAKE_DOCKER_VERSION=v9.9.9 \
    FAKE_DOCKER_SOURCE_REVISION="${source_commit}" \
    FAKE_DOCKER_REMOTE_DIGEST="${remote_digest}" \
    FAKE_DOCKER_BUILDX_FORMAT_SUPPORTED="${buildx_format_supported}" \
    FAKE_GIT_RELEASE_COMMIT="${release_commit}" \
    RELEASE_ENV_FILE="${release_env_file}" \
    RELEASE_ARTIFACT_DIR="${artifact_dir}" \
    DOCKER_PLATFORM=linux/amd64 \
        "${project_root}/docker/release/publish-release-image.sh"
}

success_dir="${temporary_root}/success"
: >"${log_file}"
success_output=$(run_publish "${success_dir}" "sha256:${digest}")
printf '%s\n' "${success_output}" | grep -Fq "Remote registry digest verified: ${exact_image}"
printf '%s\n' "${success_output}" | grep -Fq "Publication evidence: ${success_dir}/phase-manifest.txt"
grep -Fq "push ${tagged_image}" "${log_file}"
grep -Fq "buildx imagetools inspect --format {{.Manifest.Digest}} ${tagged_image}" "${log_file}"
grep -Fq 'phase_manifest_format=2' "${success_dir}/phase-manifest.txt"
grep -Fq 'phase=registry-published' "${success_dir}/phase-manifest.txt"
grep -Fq 'registry_state=verified' "${success_dir}/phase-manifest.txt"
grep -Fq "release_oid=${release_commit}" "${success_dir}/phase-manifest.txt"
grep -Fq "application_source_oid=${source_commit}" "${success_dir}/phase-manifest.txt"
grep -Fq "registry_image_digest=${exact_image}" "${success_dir}/phase-manifest.txt"
grep -Fq 'registry_assurance=registry-digest' "${success_dir}/phase-manifest.txt"

: >"${log_file}"
reuse_output=$(run_publish "${success_dir}" "sha256:${digest}")
printf '%s\n' "${reuse_output}" | grep -Fq "Reusing verified registry publication: ${exact_image}"
if grep -Fq "push ${tagged_image}" "${log_file}"; then
    echo 'Release publish contract test failed: verified publication was pushed again.' >&2
    exit 1
fi
grep -Fq "buildx imagetools inspect --format {{.Manifest.Digest}} ${tagged_image}" "${log_file}"

mismatch_dir="${temporary_root}/mismatch"
if mismatch_output=$(run_publish "${mismatch_dir}" 'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' 2>&1); then
    echo 'Release publish contract test failed: mismatched remote digest was accepted.' >&2
    exit 1
fi
printf '%s\n' "${mismatch_output}" | grep -Fq 'published registry tag resolves to sha256:bbbbbbbb'
[ ! -e "${mismatch_dir}/phase-manifest.txt" ] || {
    echo 'Release publish contract test failed: mismatch wrote publication evidence.' >&2
    exit 1
}

capability_dir="${temporary_root}/capability"
: >"${log_file}"
if capability_output=$(run_publish "${capability_dir}" "sha256:${digest}" 0 2>&1); then
    echo 'Release publish contract test failed: missing Buildx capability was accepted.' >&2
    exit 1
fi
printf '%s\n' "${capability_output}" | grep -Fq 'Buildx imagetools inspect with --format is required'
if grep -Fq "push ${tagged_image}" "${log_file}"; then
    echo 'Release publish contract test failed: image was pushed before capability preflight.' >&2
    exit 1
fi
[ ! -e "${capability_dir}/phase-manifest.txt" ] || {
    echo 'Release publish contract test failed: capability failure wrote publication evidence.' >&2
    exit 1
}

echo 'Release publish contract test passed.'
