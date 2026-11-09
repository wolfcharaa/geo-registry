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

prepared_root="${temporary_root}/prepared"
mkdir -p \
    "${prepared_root}/app" \
    "${prepared_root}/docker/compose/prod" \
    "${prepared_root}/config" \
    "${prepared_root}/docs/deployment"
cp "${project_root}/app/composer.lock" "${prepared_root}/app/composer.lock"
cp "${project_root}/docker/compose/prod/compose.yaml" \
    "${prepared_root}/docker/compose/prod/compose.yaml"
cp "${project_root}/docker/compose/prod/release.env" \
    "${prepared_root}/docker/compose/prod/release.env"
cp "${project_root}/config/prod.secrets.env.example" \
    "${prepared_root}/config/prod.secrets.env.example"
cp "${project_root}/docs/deployment/offline-release.md" \
    "${prepared_root}/docs/deployment/offline-release.md"
cp "${project_root}/docs/deployment/release-deploy.md" \
    "${prepared_root}/docs/deployment/release-deploy.md"

fake_bin="${temporary_root}/bin"
mkdir -p "${fake_bin}"
ln -s "${fixture_dir}/docker" "${fake_bin}/docker"

cat >"${fake_bin}/git" <<'EOF'
#!/bin/sh
set -eu

if [ -n "${FAKE_GIT_LOG:-}" ]; then
    printf '%s\n' "$*" >>"${FAKE_GIT_LOG}"
fi
[ "${1:-}" != init ] || {
    for argument in "$@"; do
        git_init_target=${argument}
    done
    mkdir -p "${git_init_target:?}"
    exit 0
}
[ "${1:-}" = -C ] || exit 64
shift 2

case "${1:-}" in
    status)
        if [ "${FAKE_GIT_DIRTY:-0}" = 1 ]; then
            printf '%s\n' ' M tracked-file'
        fi
        ;;
    branch)
        printf '%s\n' "${FAKE_GIT_BRANCH:-main}"
        ;;
    show-ref)
        [ "${FAKE_GIT_TAG_EXISTS:-0}" = 1 ]
        ;;
    rev-parse)
        case "${2:-}" in
            --is-inside-work-tree) printf '%s\n' true ;;
            refs/tags/*) printf '%s\n' "${FAKE_GIT_TAG_OID:-1111111111111111111111111111111111111111}" ;;
            *) printf '%s\n' 1111111111111111111111111111111111111111 ;;
        esac
        ;;
    cat-file)
        case "${2:-}" in
            -t) printf '%s\n' tag ;;
            -e) ;;
            *) exit 64 ;;
        esac
        ;;
    bundle)
        case "${2:-}" in
            create) printf '%s\n' 'fake-git-bundle' >"${3:?}" ;;
            verify) ;;
            *) exit 64 ;;
        esac
        ;;
    *)
        exit 64
        ;;
esac
EOF
chmod +x "${fake_bin}/git"

state_file="${temporary_root}/docker.state"
docker_log="${temporary_root}/docker.log"
git_log="${temporary_root}/git.log"
: >"${docker_log}"
: >"${git_log}"

run_prepare() {
    PATH="${fake_bin}:${PATH}" \
    FAKE_DOCKER_STATE="${state_file}" \
    FAKE_DOCKER_LOG="${docker_log}" \
    FAKE_GIT_LOG="${git_log}" \
    FAKE_GIT_DIRTY="${FAKE_GIT_DIRTY:-0}" \
    FAKE_GIT_BRANCH="${FAKE_GIT_BRANCH:-main}" \
    FAKE_GIT_TAG_EXISTS="${FAKE_GIT_TAG_EXISTS:-0}" \
    RELEASE_PROJECT_ROOT="${prepared_root}" \
    RELEASE_VERSION=v9.9.9 \
    DOCKER_IMAGE_PREFIX=registry.example/geo-registry \
    DOCKER_PLATFORM=linux/amd64 \
        "${project_root}/docker/release/prepare-release-source.sh"
}

prepare_output=$(run_prepare)
printf '%s\n' "${prepare_output}" | grep -Fq 'Release source prepared: v9.9.9'
printf '%s\n' "${prepare_output}" | grep -Fq 'No image, artifact, commit or tag was created.'
grep -Fq 'RELEASE_IDENTITY_FORMAT=2' "${prepared_root}/docker/compose/prod/release.env"
grep -Fq 'APP_IMAGE_TAG=v9.9.9-linux-amd64' "${prepared_root}/docker/compose/prod/release.env"
grep -Fq 'RELEASE_PLATFORM=linux/amd64' "${prepared_root}/docker/compose/prod/release.env"
if grep -Fq 'APP_IMAGE_DIGEST=' "${prepared_root}/docker/compose/prod/release.env"; then
    echo 'Release source preparation test failed: source identity contains a post-build digest.' >&2
    exit 1
fi
grep -Fq 'x-app-image: &app-image "registry.example/geo-registry/app:v9.9.9-linux-amd64"' \
    "${prepared_root}/docker/compose/prod/compose.yaml"
grep -Fq '  APP_VERSION: v9.9.9' "${prepared_root}/docker/compose/prod/compose.yaml"
grep -Fq '  APP_USER_AGENT: geo-registry/9.9' \
    "${prepared_root}/docker/compose/prod/compose.yaml"
if grep -Eq ' (add|commit|tag|push)( |$)' "${git_log}"; then
    echo 'Release source preparation test failed: preparation mutated Git history.' >&2
    exit 1
fi

compose_sha=$(sha256sum "${prepared_root}/docker/compose/prod/compose.yaml" | awk '{print $1}')
env_sha=$(sha256sum "${prepared_root}/docker/compose/prod/release.env" | awk '{print $1}')
if dirty_output=$(FAKE_GIT_DIRTY=1 run_prepare 2>&1); then
    echo 'Release source preparation test failed: dirty source was accepted.' >&2
    exit 1
fi
printf '%s\n' "${dirty_output}" | grep -Fq 'repository must be clean'
[ "$(sha256sum "${prepared_root}/docker/compose/prod/compose.yaml" | awk '{print $1}')" = "${compose_sha}" ]
[ "$(sha256sum "${prepared_root}/docker/compose/prod/release.env" | awk '{print $1}')" = "${env_sha}" ]

artifact_dir="${temporary_root}/artifacts"
: >"${docker_log}"
: >"${git_log}"
plan_output=$(
    PATH="${fake_bin}:${PATH}" \
    FAKE_DOCKER_STATE="${state_file}" \
    FAKE_DOCKER_LOG="${docker_log}" \
    FAKE_GIT_LOG="${git_log}" \
    FAKE_GIT_TAG_EXISTS=1 \
    RELEASE_PROJECT_ROOT="${prepared_root}" \
    RELEASE_SOURCE_ENV_FILE="${prepared_root}/docker/compose/prod/release.env" \
    RELEASE_COMPOSE_FILE="${prepared_root}/docker/compose/prod/compose.yaml" \
    RELEASE_VERSION=v9.9.9 \
    DOCKER_IMAGE_PREFIX=registry.example/geo-registry \
    DOCKER_PLATFORM=linux/amd64 \
        "${project_root}/docker/release/release-plan.sh"
)
printf '%s\n' "${plan_output}" | grep -Fq 'status=ready'
printf '%s\n' "${plan_output}" | grep -Fq 'tag_state=prepared'
printf '%s\n' "${plan_output}" | grep -Fq 'next_action=build-prepared-source'

: >"${docker_log}"
if mismatched_tag_output=$(
    PATH="${fake_bin}:${PATH}" \
    FAKE_DOCKER_STATE="${state_file}" \
    FAKE_DOCKER_LOG="${docker_log}" \
    FAKE_GIT_LOG="${git_log}" \
    FAKE_GIT_TAG_EXISTS=1 \
    FAKE_GIT_TAG_OID=2222222222222222222222222222222222222222 \
    RELEASE_PROJECT_ROOT="${prepared_root}" \
    RELEASE_SOURCE_ENV_FILE="${prepared_root}/docker/compose/prod/release.env" \
    RELEASE_COMPOSE_FILE="${prepared_root}/docker/compose/prod/compose.yaml" \
    RELEASE_ARTIFACT_DIR="${temporary_root}/mismatched-artifacts" \
    RELEASE_VERSION=v9.9.9 \
    DOCKER_IMAGE_PREFIX=registry.example/geo-registry \
    DOCKER_PLATFORM=linux/amd64 \
        "${project_root}/docker/scripts/build-production-image.sh" 2>&1
); then
    echo 'Release source preparation test failed: build accepted a mismatched prepared tag.' >&2
    exit 1
fi
printf '%s\n' "${mismatched_tag_output}" | grep -Fq 'must point to current HEAD before build'
if grep -Fq 'buildx build' "${docker_log}"; then
    echo 'Release source preparation test failed: mismatched tag reached Docker build.' >&2
    exit 1
fi

: >"${docker_log}"
PATH="${fake_bin}:${PATH}" \
FAKE_DOCKER_STATE="${state_file}" \
FAKE_DOCKER_LOG="${docker_log}" \
FAKE_DOCKER_EXACT_LOADED=1 \
FAKE_DOCKER_EXACT_IMAGE=registry.example/geo-registry/app@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa \
FAKE_DOCKER_IMAGE_ID=sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb \
FAKE_DOCKER_VERSION=v9.9.9 \
FAKE_DOCKER_SOURCE_REVISION=1111111111111111111111111111111111111111 \
FAKE_DOCKER_BUILDX_BUILD_SUPPORTED=1 \
FAKE_GIT_LOG="${git_log}" \
FAKE_GIT_TAG_EXISTS=1 \
RELEASE_PROJECT_ROOT="${prepared_root}" \
RELEASE_SOURCE_ENV_FILE="${prepared_root}/docker/compose/prod/release.env" \
RELEASE_COMPOSE_FILE="${prepared_root}/docker/compose/prod/compose.yaml" \
RELEASE_ARTIFACT_DIR="${artifact_dir}" \
RELEASE_VERSION=v9.9.9 \
DOCKER_IMAGE_PREFIX=registry.example/geo-registry \
DOCKER_PLATFORM=linux/amd64 \
    "${project_root}/docker/scripts/build-production-image.sh"

grep -Fq -- '--build-arg VCS_REF=1111111111111111111111111111111111111111' "${docker_log}"
grep -Fq 'built_source_oid=1111111111111111111111111111111111111111' \
    "${artifact_dir}/phase-manifest.txt"
grep -Fq 'built_config_revision=sha256:' "${artifact_dir}/phase-manifest.txt"

source_compose_sha=$(sha256sum "${prepared_root}/docker/compose/prod/compose.yaml" | awk '{print $1}')
source_env_sha=$(sha256sum "${prepared_root}/docker/compose/prod/release.env" | awk '{print $1}')
PATH="${fake_bin}:${PATH}" \
FAKE_DOCKER_STATE="${state_file}" \
FAKE_DOCKER_LOG="${docker_log}" \
FAKE_DOCKER_EXACT_LOADED=1 \
FAKE_DOCKER_EXACT_IMAGE=registry.example/geo-registry/app@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa \
FAKE_DOCKER_IMAGE_ID=sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb \
FAKE_DOCKER_VERSION=v9.9.9 \
FAKE_DOCKER_SOURCE_REVISION=1111111111111111111111111111111111111111 \
FAKE_GIT_LOG="${git_log}" \
FAKE_GIT_TAG_EXISTS=1 \
RELEASE_PROJECT_ROOT="${prepared_root}" \
RELEASE_SOURCE_ENV_FILE="${prepared_root}/docker/compose/prod/release.env" \
RELEASE_COMPOSE_FILE="${prepared_root}/docker/compose/prod/compose.yaml" \
RELEASE_ARTIFACT_DIR="${artifact_dir}" \
RELEASE_VERSION=v9.9.9 \
APP_IMAGE_DIGEST=sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa \
DOCKER_PLATFORM=linux/amd64 \
    "${project_root}/docker/release/freeze-release.sh"

[ "$(sha256sum "${prepared_root}/docker/compose/prod/compose.yaml" | awk '{print $1}')" = "${source_compose_sha}" ]
[ "$(sha256sum "${prepared_root}/docker/compose/prod/release.env" | awk '{print $1}')" = "${source_env_sha}" ]
grep -Fq 'RELEASE_ARTIFACT_FORMAT=1' "${artifact_dir}/release-artifact.env"
grep -Fq 'RELEASE_SOURCE_OID=1111111111111111111111111111111111111111' \
    "${artifact_dir}/release-artifact.env"
grep -Fq 'APP_IMAGE_DIGEST=sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' \
    "${artifact_dir}/release-artifact.env"

core_oid=$(awk -F= '$1 == "built_core_oid" {print $2}' "${artifact_dir}/phase-manifest.txt")
worker_oid=$(awk -F= '$1 == "built_worker_oid" {print $2}' "${artifact_dir}/phase-manifest.txt")
postgres_image=ghcr.io/cargosense/postgis:17-3.6-alpine@sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc
postgres_image_id=sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd
PATH="${fake_bin}:${PATH}" \
FAKE_DOCKER_STATE="${state_file}" \
FAKE_DOCKER_LOG="${docker_log}" \
FAKE_DOCKER_EXACT_LOADED=1 \
FAKE_DOCKER_EXACT_IMAGE=registry.example/geo-registry/app@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa \
FAKE_DOCKER_IMAGE_ID=sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb \
FAKE_DOCKER_VERSION=v9.9.9 \
FAKE_DOCKER_SOURCE_REVISION=1111111111111111111111111111111111111111 \
FAKE_DOCKER_REMOTE_DIGEST=sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa \
FAKE_GIT_LOG="${git_log}" \
FAKE_GIT_TAG_EXISTS=1 \
RELEASE_PROJECT_ROOT="${prepared_root}" \
RELEASE_ENV_FILE="${prepared_root}/docker/compose/prod/release.env" \
RELEASE_COMPOSE_FILE="${prepared_root}/docker/compose/prod/compose.yaml" \
RELEASE_ARTIFACT_DIR="${artifact_dir}" \
DOCKER_PLATFORM=linux/amd64 \
    "${project_root}/docker/release/publish-release-image.sh"
grep -Fq 'registry_state=verified' "${artifact_dir}/phase-manifest.txt"
grep -Fq 'registry_release_oid=1111111111111111111111111111111111111111' \
    "${artifact_dir}/phase-manifest.txt"

PATH="${fake_bin}:${PATH}" \
FAKE_DOCKER_STATE="${state_file}" \
FAKE_DOCKER_LOG="${docker_log}" \
FAKE_DOCKER_EXACT_LOADED=1 \
FAKE_DOCKER_EXACT_IMAGE=registry.example/geo-registry/app@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa \
FAKE_DOCKER_IMAGE_ID=sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb \
FAKE_DOCKER_VERSION=v9.9.9 \
FAKE_DOCKER_SOURCE_REVISION=1111111111111111111111111111111111111111 \
FAKE_DOCKER_CORE_REVISION="${core_oid}" \
FAKE_DOCKER_WORKER_REVISION="${worker_oid}" \
FAKE_DOCKER_POSTGRES_IMAGE_REFERENCE="${postgres_image}" \
FAKE_DOCKER_POSTGRES_IMAGE_ID="${postgres_image_id}" \
FAKE_DOCKER_POSTGRES_LOADED=1 \
FAKE_DOCKER_COMPOSE_IMAGES="registry.example/geo-registry/app:v9.9.9-linux-amd64
registry.example/geo-registry/app:v9.9.9-linux-amd64
${postgres_image}" \
FAKE_GIT_LOG="${git_log}" \
FAKE_GIT_TAG_EXISTS=1 \
RELEASE_PROJECT_ROOT="${prepared_root}" \
RELEASE_ENV_FILE="${prepared_root}/docker/compose/prod/release.env" \
RELEASE_COMPOSE_FILE="${prepared_root}/docker/compose/prod/compose.yaml" \
RELEASE_ARTIFACT_DIR="${artifact_dir}" \
RELEASE_OFFLINE_SCOPE=new-host-core \
DOCKER_PLATFORM=linux/amd64 \
    "${project_root}/docker/release/build-offline-payload.sh"
grep -Fq 'config_revision=sha256:' "${artifact_dir}/source-manifest.txt"
grep -Fq 'git_commit=1111111111111111111111111111111111111111' \
    "${artifact_dir}/source-manifest.txt"
grep -Fq 'offline_scope=new-host-core' "${artifact_dir}/source-manifest.txt"
grep -Fq 'image_count=2' "${artifact_dir}/source-manifest.txt"
grep -Fq 'image_2_role=postgres' "${artifact_dir}/source-manifest.txt"
grep -Fq "image_2_reference=${postgres_image}" "${artifact_dir}/source-manifest.txt"
grep -Fq "image_2_id=${postgres_image_id}" "${artifact_dir}/source-manifest.txt"
grep -Fq 'offline_payload_state=verified' "${artifact_dir}/phase-manifest.txt"
grep -Fq 'offline_payload_scope=new-host-core' "${artifact_dir}/phase-manifest.txt"
grep -Fq "save --output" "${docker_log}"
grep -Fq "${postgres_image}" "${docker_log}"

offline_archive="${temporary_root}/release-v9.9.9-offline.tar"
PATH="${fake_bin}:${PATH}" \
FAKE_DOCKER_STATE="${state_file}" \
FAKE_DOCKER_LOG="${docker_log}" \
FAKE_GIT_LOG="${git_log}" \
FAKE_GIT_TAG_EXISTS=1 \
RELEASE_PROJECT_ROOT="${prepared_root}" \
RELEASE_ENV_FILE="${prepared_root}/docker/compose/prod/release.env" \
RELEASE_COMPOSE_FILE="${prepared_root}/docker/compose/prod/compose.yaml" \
RELEASE_ARTIFACT_DIR="${artifact_dir}" \
OFFLINE_ARCHIVE_PATH="${offline_archive}" \
RELEASE_OFFLINE_SCOPE=new-host-core \
DOCKER_PLATFORM=linux/amd64 \
    "${project_root}/docker/release/build-offline-archive.sh"
tar -tf "${offline_archive}" | grep -Fxq 'release-artifact.env'
grep -Fq 'offline_export_state=verified' "${artifact_dir}/phase-manifest.txt"
grep -Fq 'offline_export_scope=new-host-core' "${artifact_dir}/phase-manifest.txt"

if grep -Eq ' (add|commit|tag|push)( |$)' "${git_log}"; then
    echo 'Release source preparation test failed: release production mutated Git history.' >&2
    exit 1
fi

echo 'Release source preparation and immutable build contract test passed.'
