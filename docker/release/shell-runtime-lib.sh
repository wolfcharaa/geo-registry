#!/bin/sh

shell_runtime_key_value_update() (
    set -eu

    shell_runtime_fail_callback=$1
    shell_runtime_context=$2
    shell_runtime_file=$3
    shell_runtime_temporary_name=$4
    shell_runtime_allowed_keys=$5
    shift 5

    [ $(( $# % 2 )) -eq 0 ] \
        || "${shell_runtime_fail_callback}" \
            "${shell_runtime_context} updates must use key/value pairs"
    [ "$#" -gt 0 ] \
        || "${shell_runtime_fail_callback}" \
            "${shell_runtime_context} update is empty"
    case "${shell_runtime_temporary_name}" in
        ''|*[!A-Za-z0-9._-]*)
            "${shell_runtime_fail_callback}" \
                "invalid temporary file label: ${shell_runtime_temporary_name}"
            ;;
    esac
    [ -n "${shell_runtime_allowed_keys}" ] \
        || "${shell_runtime_fail_callback}" \
            "${shell_runtime_context} allowed-key schema is empty"

    for shell_runtime_command in awk mktemp mv rm; do
        command -v "${shell_runtime_command}" >/dev/null 2>&1 \
            || "${shell_runtime_fail_callback}" \
                "required command is missing: ${shell_runtime_command}"
    done

    shell_runtime_directory=${shell_runtime_file%/*}
    if [ "${shell_runtime_directory}" = "${shell_runtime_file}" ]; then
        shell_runtime_directory=.
    fi
    [ -d "${shell_runtime_directory}" ] \
        || "${shell_runtime_fail_callback}" \
            "${shell_runtime_context} directory is missing: ${shell_runtime_directory}"
    [ ! -L "${shell_runtime_file}" ] \
        || "${shell_runtime_fail_callback}" \
            "${shell_runtime_context} must not be a symbolic link: ${shell_runtime_file}"
    if [ -e "${shell_runtime_file}" ]; then
        [ -f "${shell_runtime_file}" ] \
            || "${shell_runtime_fail_callback}" \
                "${shell_runtime_context} is not a regular file: ${shell_runtime_file}"
    fi

    shell_runtime_updates=$(mktemp \
        "${shell_runtime_directory}/.${shell_runtime_temporary_name}-updates.tmp.XXXXXX")
    shell_runtime_output=$(mktemp \
        "${shell_runtime_directory}/.${shell_runtime_temporary_name}-output.tmp.XXXXXX")
    # shellcheck disable=SC2317
    cleanup_shell_runtime_key_value_update() {
        rm -f "${shell_runtime_updates}" "${shell_runtime_output}"
    }
    trap cleanup_shell_runtime_key_value_update EXIT HUP INT TERM

    while [ "$#" -gt 0 ]; do
        shell_runtime_key=$1
        shell_runtime_value=$2
        shift 2

        case "${shell_runtime_key}" in
            ''|[!a-z]*|*[!a-z0-9_]*)
                "${shell_runtime_fail_callback}" \
                    "invalid ${shell_runtime_context} key: ${shell_runtime_key}"
                ;;
        esac
        case " ${shell_runtime_allowed_keys} " in
            *" ${shell_runtime_key} "*) ;;
            *)
                "${shell_runtime_fail_callback}" \
                    "unsupported ${shell_runtime_context} key: ${shell_runtime_key}"
                ;;
        esac
        case "${shell_runtime_value}" in
            *'
'*|*''*)
                "${shell_runtime_fail_callback}" \
                    "${shell_runtime_context} values must stay on one line"
                ;;
        esac
        printf '%s=%s\n' "${shell_runtime_key}" "${shell_runtime_value}" \
            >>"${shell_runtime_updates}"
    done

    shell_runtime_existing=/dev/null
    if [ -f "${shell_runtime_file}" ]; then
        shell_runtime_existing=${shell_runtime_file}
    fi

    shell_runtime_status=0
    awk -F= -v allowed_keys="${shell_runtime_allowed_keys}" '
        BEGIN {
            allowed_count = split(allowed_keys, allowed_parts, " ")
            for (allowed_position = 1; allowed_position <= allowed_count; allowed_position++) {
                allowed[allowed_parts[allowed_position]] = 1
            }
        }
        function invalid_line(line) {
            return line !~ /^[a-z][a-z0-9_]*=/ \
                || index(line, sprintf("%c", 13)) > 0
        }
        NR == FNR {
            if (invalid_line($0)) {
                exit 42
            }
            if (seen_update[$1]++) {
                exit 40
            }
            if (!($1 in allowed)) {
                exit 43
            }
            update[$1] = $0
            order[++count] = $1
            next
        }
        {
            if (invalid_line($0)) {
                exit 42
            }
            if (seen_existing[$1]++) {
                exit 41
            }
            if (!($1 in allowed)) {
                exit 43
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
    ' "${shell_runtime_updates}" "${shell_runtime_existing}" \
        >"${shell_runtime_output}" || shell_runtime_status=$?

    case "${shell_runtime_status}" in
        0) ;;
        40|41)
            "${shell_runtime_fail_callback}" \
                "${shell_runtime_context} contains duplicate keys"
            ;;
        42)
            "${shell_runtime_fail_callback}" \
                "${shell_runtime_context} contains invalid key/value lines"
            ;;
        43)
            "${shell_runtime_fail_callback}" \
                "${shell_runtime_context} contains unsupported keys"
            ;;
        *)
            "${shell_runtime_fail_callback}" \
                "cannot update ${shell_runtime_context}"
            ;;
    esac

    mv "${shell_runtime_output}" "${shell_runtime_file}"
    rm -f "${shell_runtime_updates}"
    trap - EXIT HUP INT TERM
)
