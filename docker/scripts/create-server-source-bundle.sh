#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)
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

locked_identity() {
    package_name=$1

    # shellcheck disable=SC2016
    XDEBUG_MODE=off php -r '
        $lock = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
        foreach (array_merge($lock["packages"] ?? [], $lock["packages-dev"] ?? []) as $package) {
            if (($package["name"] ?? null) !== $argv[2]) {
                continue;
            }

            if (($package["dist"]["type"] ?? null) !== "zip") {
                fwrite(STDERR, "Locked package must use a Packagist zip distribution: {$argv[2]}\n");
                exit(1);
            }

            $version = $package["version"] ?? null;
            $reference = $package["dist"]["reference"] ?? $package["source"]["reference"] ?? null;
            if (!is_string($version) || $version === "") {
                fwrite(STDERR, "Locked package has no version: {$argv[2]}\n");
                exit(1);
            }
            if (!is_string($reference) || $reference === "") {
                fwrite(STDERR, "Locked package has no revision: {$argv[2]}\n");
                exit(1);
            }

            echo $version, "|", $reference;
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

application_branch=$(git -C "${project_root}" symbolic-ref --quiet --short HEAD)
application_commit=$(git -C "${project_root}" rev-parse HEAD)
core_identity=$(locked_identity "romanfedorskij/message-bus-async")
worker_identity=$(locked_identity "romanfedorskij/message-bus-async-worker")
core_version=${core_identity%%|*}
core_reference=${core_identity#*|}
worker_version=${worker_identity%%|*}
worker_reference=${worker_identity#*|}

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

git init --bare --quiet "${staging_dir}/verifier.git"
git -C "${staging_dir}/verifier.git" bundle verify "${staging_dir}/geo-registry.bundle" >/dev/null
rm -rf -- "${staging_dir}/verifier.git"

cp "${guide_file}" "${staging_dir}/SERVER.md"

created_at=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
cat >"${staging_dir}/source-manifest.txt" <<EOF
format=geo-registry-server-source-v2
created_at=${created_at}
application.branch=${application_branch}
application.commit=${application_commit}
application.bundle.sha256=$(sha256_file "${staging_dir}/geo-registry.bundle")
message_bus_async.package=romanfedorskij/message-bus-async
message_bus_async.version=${core_version}
message_bus_async.reference=${core_reference}
message_bus_async.distribution=packagist
message_bus_async_worker.package=romanfedorskij/message-bus-async-worker
message_bus_async_worker.version=${worker_version}
message_bus_async_worker.reference=${worker_reference}
message_bus_async_worker.distribution=packagist
server_guide.sha256=$(sha256_file "${staging_dir}/SERVER.md")
runtime_data_included=false
secrets_included=false
docker_images_included=false
composer_vendor_included=false
EOF

tar -C "${staging_dir}" -cf "${output_path}" \
    source-manifest.txt \
    SERVER.md \
    geo-registry.bundle

expected_members='source-manifest.txt
SERVER.md
geo-registry.bundle'
actual_members=$(tar -tf "${output_path}")
[ "${actual_members}" = "${expected_members}" ] || fail "archive member verification failed"

echo "Source bundle created: ${output_path}"
echo "SHA-256: $(sha256_file "${output_path}")"
