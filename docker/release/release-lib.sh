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

release_source_identity_format() {
    release_source_file=$1
    release_source_format=$(release_env_value RELEASE_IDENTITY_FORMAT "${release_source_file}" || true)

    case "${release_source_format}" in
        '') printf '%s\n' 1 ;;
        2) printf '%s\n' 2 ;;
        *) release_fail "unsupported release identity format: ${release_source_format}" ;;
    esac
}

release_validate_prepared_source_files() {
    release_prepared_source_file=$1
    release_prepared_compose_file=$2

    # project_root is defined by every executable before this library is sourced.
    # shellcheck disable=SC2154
    [ "${release_prepared_source_file}" = "${project_root}/docker/compose/prod/release.env" ] \
        || release_fail "prepared source identity must come from the tagged project checkout"
    [ "${release_prepared_compose_file}" = "${project_root}/docker/compose/prod/compose.yaml" ] \
        || release_fail "prepared Compose model must come from the tagged project checkout"
}

release_identity_file() {
    release_source_file=$1
    release_artifact_directory=$2

    if [ -n "${RELEASE_ARTIFACT_ENV_FILE:-}" ]; then
        release_resolve_path "${RELEASE_ARTIFACT_ENV_FILE}"
        return
    fi

    if [ -n "$(release_env_value APP_IMAGE_DIGEST "${release_source_file}" || true)" ]; then
        printf '%s\n' "${release_source_file}"
        return
    fi

    printf '%s\n' "${release_artifact_directory}/release-artifact.env"
}

release_validate_artifact_identity() {
    release_identity_path=$1
    release_expected_version=$2
    release_expected_platform=$3
    release_expected_source_oid=$4
    release_expected_config_revision=$5
    release_actual_image_id=${6:-}

    release_require_file "${release_identity_path}"
    release_artifact_format=$(release_env_value RELEASE_ARTIFACT_FORMAT "${release_identity_path}" || true)
    if [ -z "${release_artifact_format}" ]; then
        return
    fi
    [ "${release_artifact_format}" = 1 ] \
        || release_fail "unsupported release artifact format: ${release_artifact_format}"

    [ "$(release_env_value RELEASE_VERSION "${release_identity_path}" || true)" = "${release_expected_version}" ] \
        || release_fail "artifact release version does not match prepared source"
    [ "$(release_env_value DOCKER_PLATFORM "${release_identity_path}" || true)" = "${release_expected_platform}" ] \
        || release_fail "artifact platform does not match prepared source"
    [ "$(release_env_value RELEASE_SOURCE_OID "${release_identity_path}" || true)" = "${release_expected_source_oid}" ] \
        || release_fail "artifact source OID does not match prepared source"
    [ "$(release_env_value RELEASE_CONFIG_REVISION "${release_identity_path}" || true)" = "${release_expected_config_revision}" ] \
        || release_fail "artifact config revision does not match prepared source"
    release_expected_image_id=$(release_env_value APP_IMAGE_ID "${release_identity_path}" || true)
    release_validate_image_id "${release_expected_image_id}"
    if [ -n "${release_actual_image_id}" ]; then
        [ "${release_actual_image_id}" = "${release_expected_image_id}" ] \
            || release_fail "artifact image ID does not match the verified local image"
    fi
}

release_locked_package_reference() {
    release_package_name=$1
    release_require_command php

    # shellcheck disable=SC2016,SC2154
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
    ' "${project_root}/app/composer.lock" "${release_package_name}"
}

release_validate_manifest_format() {
    case "$1" in
        1|2|3) ;;
        *) release_fail "unsupported manifest format" ;;
    esac
}

release_validate_offline_scope() {
    case "$1" in
        app-update|new-host-core) ;;
        *) release_fail "offline scope must be app-update or new-host-core" ;;
    esac
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

release_validate_git_oid() {
    release_oid=$1

    case "${release_oid}" in
        ''|*[!0-9a-f]*) release_fail "Git OID must contain lowercase hexadecimal characters" ;;
    esac
    case "${#release_oid}" in
        40|64) ;;
        *) release_fail "Git OID must contain 40 or 64 hexadecimal characters" ;;
    esac
}

release_validate_image_id() {
    release_image_id=$1

    case "${release_image_id}" in
        sha256:*) release_image_id_hash=${release_image_id#sha256:} ;;
        *) release_fail "image ID must use sha256" ;;
    esac
    case "${release_image_id_hash}" in
        *[!0-9a-f]*) release_fail "image ID must contain lowercase hexadecimal characters" ;;
    esac
    [ "${#release_image_id_hash}" -eq 64 ] \
        || release_fail "image ID must contain 64 hexadecimal characters"
}

release_local_image_matches() {
    release_local_reference=$1
    release_local_expected_id=$2
    release_local_expected_platform=$3

    docker image inspect "${release_local_reference}" >/dev/null 2>&1 \
        || return 1
    release_local_actual_id=$(docker image inspect --format '{{.Id}}' \
        "${release_local_reference}" 2>/dev/null || true)
    release_local_actual_platform=$(docker image inspect \
        --format '{{.Os}}/{{.Architecture}}' \
        "${release_local_reference}" 2>/dev/null || true)
    [ "${release_local_actual_id}" = "${release_local_expected_id}" ] \
        && [ "${release_local_actual_platform}" = "${release_local_expected_platform}" ]
}

release_validate_local_image() {
    release_local_reference=$1
    release_local_expected_id=$2
    release_local_expected_platform=$3
    release_local_role=$4

    release_validate_image_id "${release_local_expected_id}"
    release_validate_platform "${release_local_expected_platform}"
    docker image inspect "${release_local_reference}" >/dev/null 2>&1 \
        || release_fail "${release_local_role} image is not loaded: ${release_local_reference}"
    release_local_actual_id=$(docker image inspect --format '{{.Id}}' \
        "${release_local_reference}" 2>/dev/null || true)
    [ "${release_local_actual_id}" = "${release_local_expected_id}" ] \
        || release_fail "${release_local_role} image ID is ${release_local_actual_id:-unavailable}, expected ${release_local_expected_id}"
    release_local_actual_platform=$(docker image inspect \
        --format '{{.Os}}/{{.Architecture}}' \
        "${release_local_reference}" 2>/dev/null || true)
    [ "${release_local_actual_platform}" = "${release_local_expected_platform}" ] \
        || release_fail "${release_local_role} image platform is ${release_local_actual_platform:-unavailable}, expected ${release_local_expected_platform}"
}

release_validate_size() {
    case "$1" in
        ''|*[!0-9]*) release_fail "artifact size must be a non-negative integer" ;;
    esac
}

release_file_size() {
    wc -c <"$1" | tr -d '[:space:]'
}

release_config_revision() (
    set -eu

    for release_config_command in mktemp rm; do
        release_require_command "${release_config_command}"
    done
    [ "$#" -gt 0 ] || release_fail "at least one config input is required"

    release_config_temporary=$(mktemp)
    # shellcheck disable=SC2317
    cleanup_release_config_revision() {
        rm -f "${release_config_temporary}"
    }
    trap cleanup_release_config_revision EXIT INT TERM

    for release_config_file in "$@"; do
        release_require_file "${release_config_file}"
        printf '%s\n' "$(release_sha256 "${release_config_file}")" >>"${release_config_temporary}"
    done

    printf 'sha256:%s\n' "$(release_sha256 "${release_config_temporary}")"
)

release_validate_phase_manifest_format() {
    case "$1" in
        1|2) ;;
        *) release_fail "unsupported phase manifest format" ;;
    esac
}

release_phase_manifest_expect() {
    release_phase_file=$1
    release_phase_key=$2
    release_phase_expected=$3

    release_phase_actual=$(release_manifest_value "${release_phase_key}" "${release_phase_file}" || true)
    [ "${release_phase_actual}" = "${release_phase_expected}" ] \
        || release_fail "phase binding ${release_phase_key} is ${release_phase_actual:-missing}, expected ${release_phase_expected}"
}

release_phase_manifest_update() (
    set -eu

    release_phase_file=$1
    shift
    release_phase_argument_count=$#
    [ $((release_phase_argument_count % 2)) -eq 0 ] \
        || release_fail "phase manifest updates must use key/value pairs"
    [ "$#" -gt 0 ] || release_fail "phase manifest update is empty"

    for release_phase_command in awk mktemp mv rm; do
        release_require_command "${release_phase_command}"
    done

    release_phase_directory=${release_phase_file%/*}
    release_phase_updates=$(mktemp "${release_phase_directory}/.phase-updates.tmp.XXXXXX")
    release_phase_output=$(mktemp "${release_phase_directory}/.phase-manifest.tmp.XXXXXX")
    # shellcheck disable=SC2317
    cleanup_release_phase_update() {
        rm -f "${release_phase_updates}" "${release_phase_output}"
    }
    trap cleanup_release_phase_update EXIT INT TERM

    while [ "$#" -gt 0 ]; do
        release_phase_key=$1
        release_phase_value=$2
        shift 2

        case "${release_phase_key}" in
            ''|*[!a-z0-9_]*) release_fail "invalid phase manifest key: ${release_phase_key}" ;;
        esac
        case "${release_phase_value}" in
            *'
'*|*''*) release_fail "phase manifest values must stay on one line" ;;
        esac
        printf '%s=%s\n' "${release_phase_key}" "${release_phase_value}" >>"${release_phase_updates}"
    done

    release_phase_existing=/dev/null
    if [ -f "${release_phase_file}" ]; then
        release_phase_existing=${release_phase_file}
        release_validate_phase_manifest_format \
            "$(release_manifest_value phase_manifest_format "${release_phase_file}" || true)"
    fi

    if ! awk -F= '
        NR == FNR {
            if (seen_update[$1]++) {
                exit 40
            }
            update[$1] = $0
            order[++count] = $1
            next
        }
        {
            if (seen_existing[$1]++) {
                exit 41
            }
            if (!($1 in update)) {
                print
            }
        }
        END {
            for (position = 1; position <= count; position++) {
                print update[order[position]]
            }
        }
    ' "${release_phase_updates}" "${release_phase_existing}" >"${release_phase_output}"; then
        release_fail "phase manifest contains duplicate keys"
    fi

    mv "${release_phase_output}" "${release_phase_file}"
    rm -f "${release_phase_updates}"
    trap - EXIT INT TERM
)

release_acquire_output_lock() {
    release_lock_file=$1
    release_lock_operation=$2
    release_lock_version=$3
    release_lock_platform=$4

    for release_lock_command in date flock id sed; do
        release_require_command "${release_lock_command}"
    done

    # Append mode avoids truncating active-owner evidence before the lock is held.
    exec 9>>"${release_lock_file}"
    if ! flock -n 9; then
        echo "Active release output lock: ${release_lock_file}" >&2
        sed -n '1,20p' "${release_lock_file}" >&2 || true
        release_fail "another release operation owns ${release_lock_version} (${release_lock_platform})"
    fi

    : >"${release_lock_file}"
    {
        printf '%s\n' 'lock_format=1'
        printf 'operation=%s\n' "${release_lock_operation}"
        printf 'release_version=%s\n' "${release_lock_version}"
        printf 'platform=%s\n' "${release_lock_platform}"
        printf 'pid=%s\n' "$$"
        printf 'actor=%s\n' "$(id -un)"
        printf 'started_at=%s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
    } >&9
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

release_registry_image_is_loaded() {
    release_tagged_image=$1
    release_exact_image=$2

    docker image inspect "${release_exact_image}" >/dev/null 2>&1 \
        && release_image_has_digest "${release_tagged_image}" "${release_exact_image}"
}

release_validate_runtime_image() {
    release_runtime_image=$1
    release_platform=$2
    release_version=$3

    docker image inspect "${release_runtime_image}" >/dev/null 2>&1 \
        || release_fail "runtime image is not loaded: ${release_runtime_image}"

    release_actual_platform=$(docker image inspect --format '{{.Os}}/{{.Architecture}}' "${release_runtime_image}")
    [ "${release_actual_platform}" = "${release_platform}" ] \
        || release_fail "image platform is ${release_actual_platform}, expected ${release_platform}"

    release_actual_version=$(docker image inspect --format '{{index .Config.Labels "org.opencontainers.image.version"}}' "${release_runtime_image}")
    [ "${release_actual_version}" = "${release_version}" ] \
        || release_fail "image version label is ${release_actual_version}, expected ${release_version}"

    docker run --rm --entrypoint php "${release_runtime_image}" -r '
        $errors = [];
        foreach (["pcntl", "pdo_pgsql", "pgsql", "posix", "sockets", "uv", "xmlreader"] as $extension) {
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
            if ($driver !== Revolt\EventLoop\Driver\UvDriver::class) {
                $errors[] = "unexpected production event-loop driver: " . $driver;
            }
        }
        if ($errors !== []) {
            fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
            exit(1);
        }
    '
}

release_validate_registry_image() {
    release_tagged_image=$1
    release_exact_image=$2
    release_platform=$3
    release_version=$4

    release_registry_image_is_loaded "${release_tagged_image}" "${release_exact_image}" \
        || release_fail "tagged image does not resolve to expected digest: ${release_tagged_image}"

    release_validate_runtime_image \
        "${release_tagged_image}" "${release_platform}" "${release_version}"
}

release_validate_remote_registry_image() {
    release_tagged_image=$1
    release_exact_image=$2

    case "${release_exact_image}" in
        *@sha256:*) release_expected_digest=${release_exact_image#*@} ;;
        *) release_fail "exact remote image must contain a sha256 digest" ;;
    esac
    release_validate_digest "${release_expected_digest}"

    release_remote_tag_digest=$(docker buildx imagetools inspect \
        --format '{{.Manifest.Digest}}' "${release_tagged_image}") \
        || release_fail "cannot inspect published registry tag: ${release_tagged_image}"
    release_validate_digest "${release_remote_tag_digest}"
    [ "${release_remote_tag_digest}" = "${release_expected_digest}" ] \
        || release_fail "published registry tag resolves to ${release_remote_tag_digest}, expected ${release_expected_digest}"

    release_remote_exact_digest=$(docker buildx imagetools inspect \
        --format '{{.Manifest.Digest}}' "${release_exact_image}") \
        || release_fail "cannot inspect published registry digest: ${release_exact_image}"
    release_validate_digest "${release_remote_exact_digest}"
    [ "${release_remote_exact_digest}" = "${release_expected_digest}" ] \
        || release_fail "published exact image resolves to ${release_remote_exact_digest}, expected ${release_expected_digest}"

    echo "Remote registry digest verified: ${release_exact_image}"
}
