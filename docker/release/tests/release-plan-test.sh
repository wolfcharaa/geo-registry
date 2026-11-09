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
    rev-parse)
        printf '%s\n' 1111111111111111111111111111111111111111
        ;;
    branch)
        printf '%s\n' "${FAKE_GIT_BRANCH:-main}"
        ;;
    status)
        if [ "${FAKE_GIT_DIRTY:-0}" = 1 ]; then
            printf '%s\n' ' M tracked-file'
        fi
        ;;
    show-ref)
        [ "${FAKE_GIT_TAG_EXISTS:-0}" = 1 ]
        ;;
    *)
        exit 64
        ;;
esac
EOF
chmod +x "${fake_bin}/git"

state_file="${temporary_root}/docker.state"
log_file="${temporary_root}/docker.log"

run_plan() {
    test_offline_scope=${TEST_OFFLINE_SCOPE-app-update}
    PATH="${fake_bin}:${PATH}" \
    FAKE_DOCKER_STATE="${state_file}" \
    FAKE_DOCKER_LOG="${log_file}" \
    FAKE_GIT_DIRTY="${FAKE_GIT_DIRTY:-0}" \
    FAKE_GIT_BRANCH="${FAKE_GIT_BRANCH:-main}" \
    FAKE_GIT_TAG_EXISTS="${FAKE_GIT_TAG_EXISTS:-0}" \
    RELEASE_VERSION=v9.9.9 \
    DOCKER_IMAGE_PREFIX=registry.example/geo-registry \
    DOCKER_PLATFORM=linux/amd64 \
    RELEASE_OFFLINE_SCOPE="${test_offline_scope}" \
        "${project_root}/docker/release/release-plan.sh"
}

: >"${log_file}"
ready_output=$(run_plan)
printf '%s\n' "${ready_output}" | grep -Fq 'Release plan (read-only)'
printf '%s\n' "${ready_output}" | grep -Fq 'status=ready'
printf '%s\n' "${ready_output}" | grep -Fq 'source_oid=1111111111111111111111111111111111111111'
printf '%s\n' "${ready_output}" | grep -Fq 'tag_state=absent'
printf '%s\n' "${ready_output}" | grep -Fq 'offline_scope=app-update'
printf '%s\n' "${ready_output}" | grep -Fq 'image_reference=registry.example/geo-registry/app:v9.9.9-linux-amd64'
printf '%s\n' "${ready_output}" | grep -Fq 'external_mutations=none'
grep -Fq 'buildx version' "${log_file}"
grep -Fq 'buildx build --help' "${log_file}"
if grep -Eq '^(push|pull|load|run) ' "${log_file}"; then
    echo 'Release plan test failed: read-only plan attempted a Docker mutation.' >&2
    exit 1
fi

if dirty_output=$(FAKE_GIT_DIRTY=1 run_plan 2>&1); then
    echo 'Release plan test failed: dirty source was accepted.' >&2
    exit 1
fi
printf '%s\n' "${dirty_output}" | grep -Fq 'status=blocked'
printf '%s\n' "${dirty_output}" | grep -Fq 'source_state=dirty'
printf '%s\n' "${dirty_output}" | grep -Fq 'no build, tag, push or artifact mutation was attempted'

if tag_output=$(FAKE_GIT_TAG_EXISTS=1 run_plan 2>&1); then
    echo 'Release plan test failed: existing release tag was accepted.' >&2
    exit 1
fi
printf '%s\n' "${tag_output}" | grep -Fq 'tag_state=existing'
printf '%s\n' "${tag_output}" | grep -Fq 'status=blocked'

if branch_output=$(FAKE_GIT_BRANCH=feature/test run_plan 2>&1); then
    echo 'Release plan test failed: wrong branch was accepted.' >&2
    exit 1
fi
printf '%s\n' "${branch_output}" | grep -Fq 'current_branch=feature/test'
printf '%s\n' "${branch_output}" | grep -Fq 'status=blocked'

core_output=$(TEST_OFFLINE_SCOPE=new-host-core run_plan)
printf '%s\n' "${core_output}" | grep -Fq 'offline_scope=new-host-core'

if invalid_scope_output=$(TEST_OFFLINE_SCOPE=everything run_plan 2>&1); then
    echo 'Release plan test failed: unknown offline scope was accepted.' >&2
    exit 1
fi
printf '%s\n' "${invalid_scope_output}" \
    | grep -Fq 'offline scope must be app-update or new-host-core'

echo 'Release read-only plan test passed.'
