#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)

fail() {
    echo "Production image build error: $*" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "required command is missing: $1"
}

require_clean_repository() {
    repository=$1
    label=$2

    git -C "${repository}" rev-parse --is-inside-work-tree >/dev/null 2>&1 \
        || fail "${label} repository is unavailable: ${repository}"
    [ -z "$(git -C "${repository}" status --porcelain)" ] \
        || fail "${label} repository has uncommitted changes"
}

locked_reference() {
    package_name=$1

    # shellcheck disable=SC2016
    XDEBUG_MODE=off php -r '
        $lock = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
        foreach (array_merge($lock["packages"] ?? [], $lock["packages-dev"] ?? []) as $package) {
            if (($package["name"] ?? null) === $argv[2]) {
                if (($package["dist"]["type"] ?? null) !== "zip") {
                    fwrite(STDERR, "Locked package must use a Packagist zip distribution: {$argv[2]}\n");
                    exit(1);
                }

                $reference = $package["dist"]["reference"] ?? $package["source"]["reference"] ?? null;
                if (!is_string($reference) || $reference === "") {
                    fwrite(STDERR, "Locked package has no release reference: {$argv[2]}\n");
                    exit(1);
                }

                echo $reference;
                exit(0);
            }
        }
        fwrite(STDERR, "Locked package is missing: {$argv[2]}\n");
        exit(1);
    ' "${project_root}/app/composer.lock" "${package_name}"
}

if [ "$(id -u)" -eq 0 ] || [ -n "${SUDO_USER:-}" ]; then
    fail "run as the build user, without sudo"
fi

for command_name in docker git php tr; do
    require_command "${command_name}"
done

image_prefix=${DOCKER_IMAGE_PREFIX:-}
release_version=${RELEASE_VERSION:-}
platform=${DOCKER_PLATFORM:-linux/amd64}

case "${image_prefix}" in
    ''|*' '*|*@*|*/) fail "DOCKER_IMAGE_PREFIX must be a full registry namespace prefix without a tag or trailing slash" ;;
esac
case "${release_version}" in
    ''|*' '*|*/*|*@*|*:* ) fail "RELEASE_VERSION must be one release identifier" ;;
esac
case "${platform}" in
    linux/amd64|linux/arm64) ;;
    *) fail "DOCKER_PLATFORM must be linux/amd64 or linux/arm64" ;;
esac

require_clean_repository "${project_root}" geo-registry
core_revision=$(locked_reference romanfedorskij/message-bus-async)
worker_revision=$(locked_reference romanfedorskij/message-bus-async-worker)

platform_slug=$(printf '%s' "${platform}" | tr / -)
image_tag="${release_version}-${platform_slug}"
image="${image_prefix}/app:${image_tag}"
revision=$(git -C "${project_root}" rev-parse HEAD)

docker buildx build \
    --file "${project_root}/docker/php/production.Dockerfile" \
    --load \
    --platform "${platform}" \
    --build-arg "RELEASE_VERSION=${release_version}" \
    --build-arg "VCS_REF=${revision}" \
    --build-arg "MESSAGE_BUS_CORE_REF=${core_revision}" \
    --build-arg "MESSAGE_BUS_WORKER_REF=${worker_revision}" \
    --tag "${image}" \
    "${project_root}"

image_reference=$(docker image inspect --format '{{index .RepoDigests 0}}' "${image}")
case "${image_reference}" in
    "${image_prefix}/app@sha256:"*) image_digest=${image_reference#*@} ;;
    *) fail "built image does not expose the expected repository digest" ;;
esac

echo "Production image built: ${image}"
echo "Release metadata:"
echo "DOCKER_IMAGE_PREFIX=${image_prefix}"
echo "APP_IMAGE_TAG=${image_tag}"
echo "APP_IMAGE_DIGEST=${image_digest}"
echo "RELEASE_VERSION=${release_version}"
