#!/bin/sh
set -eu

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH= cd -- "${script_dir}/../.." && pwd)
workspace_root=$(CDPATH= cd -- "${project_root}/.." && pwd)
core_root="${workspace_root}/library/MessageBusAsync"
worker_root="${workspace_root}/library/MessageBusAsyncWorker"
lock_file="${project_root}/app/composer.lock"
guide_file="${project_root}/docs/migration/server-handoff.md"

fail() {
    echo "Source bundle error: $*" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "required command is missing: $1"
}

require_repository() {
    repository=$1
    label=$2

    git -C "${repository}" rev-parse --is-inside-work-tree >/dev/null 2>&1 \
        || fail "${label} repository is unavailable: ${repository}"
    [ -z "$(git -C "${repository}" status --porcelain)" ] \
        || fail "${label} repository has uncommitted changes: ${repository}"
    git -C "${repository}" symbolic-ref --quiet --short HEAD >/dev/null 2>&1 \
        || fail "${label} repository must be on a named branch"
}

locked_reference() {
    package_name=$1

    XDEBUG_MODE=off php -r '
        $lock = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
        foreach (array_merge($lock["packages"] ?? [], $lock["packages-dev"] ?? []) as $package) {
            if (($package["name"] ?? null) !== $argv[2]) {
                continue;
            }

            $reference = $package["dist"]["reference"] ?? $package["source"]["reference"] ?? null;
            if (!is_string($reference) || $reference === "") {
                fwrite(STDERR, "Locked package has no revision: {$argv[2]}\n");
                exit(1);
            }

            echo $reference;
            exit(0);
        }

        fwrite(STDERR, "Locked package is missing: {$argv[2]}\n");
        exit(1);
    ' "${lock_file}" "${package_name}"
}

sha256_file() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | awk '{print $1}'
        return
    fi

    shasum -a 256 "$1" | awk '{print $1}'
}

for command_name in git php tar awk mktemp; do
    require_command "${command_name}"
done

[ -f "${lock_file}" ] || fail "Composer lock is unavailable: ${lock_file}"
[ -f "${guide_file}" ] || fail "Server handoff guide is unavailable: ${guide_file}"

require_repository "${project_root}" "geo-registry"
require_repository "${core_root}" "message-bus-async"
require_repository "${worker_root}" "message-bus-async-worker"

application_branch=$(git -C "${project_root}" symbolic-ref --quiet --short HEAD)
application_commit=$(git -C "${project_root}" rev-parse HEAD)
core_branch=$(git -C "${core_root}" symbolic-ref --quiet --short HEAD)
core_commit=$(git -C "${core_root}" rev-parse HEAD)
worker_branch=$(git -C "${worker_root}" symbolic-ref --quiet --short HEAD)
worker_commit=$(git -C "${worker_root}" rev-parse HEAD)

core_locked_reference=$(locked_reference "romanfedorskij/message-bus-async")
worker_locked_reference=$(locked_reference "romanfedorskij/message-bus-async-worker")

[ "${core_locked_reference}" = "${core_commit}" ] \
    || fail "Composer locks message-bus-async at ${core_locked_reference}, current HEAD is ${core_commit}"
[ "${worker_locked_reference}" = "${worker_commit}" ] \
    || fail "Composer locks message-bus-async-worker at ${worker_locked_reference}, current HEAD is ${worker_commit}"

output_path=${SERVER_SOURCE_OUTPUT:-/tmp/geo-registry-server-source-$(git -C "${project_root}" rev-parse --short=12 HEAD).tar}
case "${output_path}" in
    /*) ;;
    *) output_path="${project_root}/${output_path}" ;;
esac

[ ! -e "${output_path}" ] || fail "output already exists: ${output_path}"
output_dir=$(dirname -- "${output_path}")
[ -d "${output_dir}" ] || fail "output directory does not exist: ${output_dir}"
[ -w "${output_dir}" ] || fail "output directory is not writable: ${output_dir}"

staging_dir=$(mktemp -d "${TMPDIR:-/tmp}/geo-registry-source.XXXXXX")
cleanup() {
    case "${staging_dir}" in
        "${TMPDIR:-/tmp}"/geo-registry-source.*)
            rm -rf -- "${staging_dir}"
            ;;
    esac
}
trap cleanup EXIT HUP INT TERM

git -C "${project_root}" bundle create "${staging_dir}/geo-registry.bundle" "${application_branch}"
git -C "${core_root}" bundle create "${staging_dir}/message-bus-async.bundle" "${core_branch}"
git -C "${worker_root}" bundle create "${staging_dir}/message-bus-async-worker.bundle" "${worker_branch}"

git init --bare --quiet "${staging_dir}/verifier.git"
git -C "${staging_dir}/verifier.git" bundle verify "${staging_dir}/geo-registry.bundle" >/dev/null
git -C "${staging_dir}/verifier.git" bundle verify "${staging_dir}/message-bus-async.bundle" >/dev/null
git -C "${staging_dir}/verifier.git" bundle verify "${staging_dir}/message-bus-async-worker.bundle" >/dev/null
rm -rf -- "${staging_dir}/verifier.git"

cp "${guide_file}" "${staging_dir}/SERVER.md"

created_at=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
cat >"${staging_dir}/source-manifest.txt" <<EOF
format=geo-registry-server-source-v1
created_at=${created_at}
application.branch=${application_branch}
application.commit=${application_commit}
application.bundle.sha256=$(sha256_file "${staging_dir}/geo-registry.bundle")
message_bus_async.branch=${core_branch}
message_bus_async.commit=${core_commit}
message_bus_async.bundle.sha256=$(sha256_file "${staging_dir}/message-bus-async.bundle")
message_bus_async_worker.branch=${worker_branch}
message_bus_async_worker.commit=${worker_commit}
message_bus_async_worker.bundle.sha256=$(sha256_file "${staging_dir}/message-bus-async-worker.bundle")
server_guide.sha256=$(sha256_file "${staging_dir}/SERVER.md")
runtime_data_included=false
secrets_included=false
docker_images_included=false
EOF

tar -C "${staging_dir}" -cf "${output_path}" \
    source-manifest.txt \
    SERVER.md \
    geo-registry.bundle \
    message-bus-async.bundle \
    message-bus-async-worker.bundle

expected_members='source-manifest.txt
SERVER.md
geo-registry.bundle
message-bus-async.bundle
message-bus-async-worker.bundle'
actual_members=$(tar -tf "${output_path}")
[ "${actual_members}" = "${expected_members}" ] || fail "archive member verification failed"

echo "Source bundle created: ${output_path}"
echo "SHA-256: $(sha256_file "${output_path}")"
