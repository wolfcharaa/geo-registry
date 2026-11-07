#!/bin/sh

release_fail() {
    echo "Release error: $*" >&2
    exit 1
}

release_require_command() {
    command -v "$1" >/dev/null 2>&1 || release_fail "required command is missing: $1"
}

release_require_file() {
    [ -f "$1" ] || release_fail "file not found: $1"
}

release_resolve_path() {
    release_path=$1

    # project_root is defined by every executable before this library is sourced.
    # shellcheck disable=SC2154
    case "${release_path}" in
        /*) printf '%s\n' "${release_path}" ;;
        *) printf '%s\n' "${project_root}/${release_path}" ;;
    esac
}

release_env_value() {
    release_key=$1
    release_file=$2

    awk -F= -v key="${release_key}" '
        $1 == key {
            print substr($0, index($0, "=") + 1)
            found = 1
            exit
        }
        END {
            if (!found) {
                exit 1
            }
        }
    ' "${release_file}"
}

release_manifest_value() {
    release_key=$1
    release_file=$2

    release_env_value "${release_key}" "${release_file}"
}

release_sha256() {
    release_file=$1

    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "${release_file}" | awk '{print $1}'
        return
    fi

    shasum -a 256 "${release_file}" | awk '{print $1}'
}

release_validate_version() {
    printf '%s\n' "$1" | grep -Eq '^v[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$' \
        || release_fail "release version must use vMAJOR.MINOR.PATCH syntax"
}

release_validate_digest() {
    release_digest=$1

    case "${release_digest}" in
        sha256:*) release_digest_hash=${release_digest#sha256:} ;;
        *) release_fail "image digest must use sha256" ;;
    esac
    case "${release_digest_hash}" in
        *[!0-9a-f]*) release_fail "image digest must contain lowercase hexadecimal characters" ;;
    esac
    [ "${#release_digest_hash}" -eq 64 ] \
        || release_fail "image digest must contain 64 hexadecimal characters"
}

release_validate_platform() {
    case "$1" in
        linux/amd64|linux/arm64) ;;
        *) release_fail "platform must be linux/amd64 or linux/arm64" ;;
    esac
}

release_validate_prefix() {
    case "$1" in
        ''|*' '*|*@*|*/) release_fail "DOCKER_IMAGE_PREFIX must be a full registry namespace prefix without a tag or trailing slash" ;;
    esac
}

release_image_has_digest() {
    release_image=$1
    release_expected=$2

    docker image inspect --format '{{range .RepoDigests}}{{println .}}{{end}}' "${release_image}" 2>/dev/null \
        | grep -Fxq "${release_expected}"
}

release_validate_image() {
    release_tagged_image=$1
    release_exact_image=$2
    release_platform=$3
    release_version=$4

    docker image inspect "${release_exact_image}" >/dev/null 2>&1 \
        || release_fail "exact image is not loaded: ${release_exact_image}"
    release_image_has_digest "${release_tagged_image}" "${release_exact_image}" \
        || release_fail "tagged image does not resolve to expected digest: ${release_tagged_image}"

    release_actual_platform=$(docker image inspect --format '{{.Os}}/{{.Architecture}}' "${release_exact_image}")
    [ "${release_actual_platform}" = "${release_platform}" ] \
        || release_fail "image platform is ${release_actual_platform}, expected ${release_platform}"

    release_actual_version=$(docker image inspect --format '{{index .Config.Labels "org.opencontainers.image.version"}}' "${release_exact_image}")
    [ "${release_actual_version}" = "${release_version}" ] \
        || release_fail "image version label is ${release_actual_version}, expected ${release_version}"

    docker run --rm --entrypoint php "${release_exact_image}" -r '
        $errors = [];
        foreach (["ev", "pcntl", "pdo_pgsql", "pgsql", "posix", "pq", "raphf", "sockets", "xmlreader"] as $extension) {
            if (!extension_loaded($extension)) {
                $errors[] = "missing extension: " . $extension;
            }
        }
        if (!is_file("vendor/autoload.php")) {
            $errors[] = "missing vendor/autoload.php";
        } else {
            require "vendor/autoload.php";
            if (!class_exists("App\\Application\\DependencyInjection\\CliRuntimeModule")) {
                $errors[] = "application autoload is incomplete";
            }
            $driver = Revolt\EventLoop::getDriver()::class;
            if ($driver !== Revolt\EventLoop\Driver\EvDriver::class) {
                $errors[] = "unexpected production event-loop driver: " . $driver;
            }
        }
        if ($errors !== []) {
            fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
            exit(1);
        }
    '
}
