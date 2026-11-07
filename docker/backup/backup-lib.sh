#!/bin/sh

backup_fail() {
    echo "Data backup error: $*" >&2
    exit 1
}

backup_require_command() {
    command -v "$1" >/dev/null 2>&1 || backup_fail "required command is missing: $1"
}

backup_sha256() {
    backup_sha256_file=$1

    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "${backup_sha256_file}" | awk '{print $1}'
        return
    fi

    shasum -a 256 "${backup_sha256_file}" | awk '{print $1}'
}

backup_file_bytes() {
    wc -c <"$1" | tr -d ' '
}

backup_manifest_value() {
    backup_manifest_key=$1
    backup_manifest_file=$2

    awk -F= -v key="${backup_manifest_key}" '
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
    ' "${backup_manifest_file}"
}

backup_validate_component() {
    case "$1" in
        application-postgres|nominatim) ;;
        *) backup_fail "BACKUP_COMPONENT must be application-postgres or nominatim" ;;
    esac
}

backup_validate_identifier() {
    backup_identifier_name=$1
    backup_identifier_value=$2

    case "${backup_identifier_value}" in
        ''|[0-9]*|*[!A-Za-z0-9_]*)
            backup_fail "${backup_identifier_name} must be a simple PostgreSQL identifier"
            ;;
    esac
}

backup_validate_sha256() {
    backup_sha256_name=$1
    backup_sha256_value=$2

    case "${backup_sha256_value}" in
        *[!0-9a-f]*) backup_fail "${backup_sha256_name} must contain lowercase hexadecimal characters" ;;
    esac
    [ "${#backup_sha256_value}" -eq 64 ] \
        || backup_fail "${backup_sha256_name} must contain 64 hexadecimal characters"
}

backup_validate_revision() {
    backup_revision_name=$1
    backup_revision_value=$2

    case "${backup_revision_value}" in
        *[!0-9a-f]*) backup_fail "${backup_revision_name} must contain lowercase hexadecimal characters" ;;
    esac
    [ "${#backup_revision_value}" -eq 40 ] \
        || backup_fail "${backup_revision_name} must contain a full 40-character Git revision"
}

backup_validate_positive_integer() {
    backup_integer_name=$1
    backup_integer_value=$2

    case "${backup_integer_value}" in
        ''|*[!0-9]*) backup_fail "${backup_integer_name} must be a positive integer" ;;
    esac
    [ "${backup_integer_value}" -gt 0 ] \
        || backup_fail "${backup_integer_name} must be greater than zero"
}

backup_validate_container() {
    backup_container=$1

    [ -n "${backup_container}" ] || backup_fail "database container was not resolved"
    [ "$(docker inspect --format '{{.State.Running}}' "${backup_container}" 2>/dev/null)" = true ] \
        || backup_fail "database container is not running: ${backup_container}"
}

backup_container_environment_value() {
    backup_container=$1
    backup_key=$2

    docker inspect --format '{{range .Config.Env}}{{println .}}{{end}}' "${backup_container}" \
        | awk -F= -v key="${backup_key}" '
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
        '
}

backup_resolve_compose_container() {
    backup_project_root=$1
    backup_service=$2

    COMPOSE_ENV_FILE="${COMPOSE_ENV_FILE:-config/.env}" \
        "${backup_project_root}/docker/compose/dev.sh" ps --quiet "${backup_service}"
}

backup_require_directory() {
    backup_directory_name=$1
    backup_directory=$2

    case "${backup_directory}" in
        /*) ;;
        *) backup_fail "${backup_directory_name} must be an absolute path" ;;
    esac
    [ "${backup_directory}" != / ] \
        || backup_fail "${backup_directory_name} cannot be the filesystem root"
    [ -d "${backup_directory}" ] \
        || backup_fail "${backup_directory_name} does not exist: ${backup_directory}"
    [ -r "${backup_directory}" ] \
        || backup_fail "${backup_directory_name} is not readable: ${backup_directory}"
}

backup_validate_bundle() {
    backup_directory=$1
    backup_manifest="${backup_directory}/manifest.txt"

    backup_require_directory BACKUP_DIR "${backup_directory}"
    [ -f "${backup_manifest}" ] || backup_fail "backup manifest is missing: ${backup_manifest}"
    [ "$(backup_manifest_value format "${backup_manifest}" || true)" = geo-registry-postgres-backup-v1 ] \
        || backup_fail "unsupported backup manifest format"

    backup_component=$(backup_manifest_value component "${backup_manifest}" || true)
    backup_validate_component "${backup_component}"
    backup_dump_name=$(backup_manifest_value dump_file "${backup_manifest}" || true)
    [ "${backup_dump_name}" = "${backup_component}.dump" ] \
        || backup_fail "manifest dump file does not match its component"
    backup_dump="${backup_directory}/${backup_dump_name}"
    [ -f "${backup_dump}" ] || backup_fail "backup dump is missing: ${backup_dump}"

    backup_expected_bytes=$(backup_manifest_value dump_bytes "${backup_manifest}" || true)
    backup_validate_positive_integer dump_bytes "${backup_expected_bytes}"
    backup_actual_bytes=$(backup_file_bytes "${backup_dump}")
    [ "${backup_actual_bytes}" = "${backup_expected_bytes}" ] \
        || backup_fail "backup dump size does not match the manifest"

    backup_expected_sha256=$(backup_manifest_value dump_sha256 "${backup_manifest}" || true)
    backup_validate_sha256 dump_sha256 "${backup_expected_sha256}"
    backup_actual_sha256=$(backup_sha256 "${backup_dump}")
    [ "${backup_actual_sha256}" = "${backup_expected_sha256}" ] \
        || backup_fail "backup dump checksum does not match the manifest"
}
