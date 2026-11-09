#!/bin/sh
set -eu

project_root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)

for command_name in git mktemp rm sed sh shellcheck tr wc; do
    command -v "${command_name}" >/dev/null 2>&1 || {
        echo "Shell check error: required command is missing: ${command_name}" >&2
        exit 1
    }
done

candidate_list=$(mktemp)
shell_list=$(mktemp)
cleanup_shell_check() {
    rm -f "${candidate_list}" "${shell_list}"
}
trap cleanup_shell_check EXIT HUP INT TERM

git -C "${project_root}" ls-files --cached --others --exclude-standard \
    >"${candidate_list}"

set --
while IFS= read -r relative_path; do
    [ -n "${relative_path}" ] || continue
    absolute_path="${project_root}/${relative_path}"
    [ -f "${absolute_path}" ] || continue
    first_line=$(sed -n '1p' "${absolute_path}")
    case "${first_line}" in
        '#!/bin/sh')
            printf '%s\n' "${relative_path}" >>"${shell_list}"
            set -- "$@" "${absolute_path}"
            ;;
        '#!/bin/bash'|'#!/usr/bin/env bash'|'#!/usr/bin/env sh')
            echo "Shell check error: unsupported shell shebang in ${relative_path}: ${first_line}" >&2
            exit 1
            ;;
        *)
            case "${relative_path}" in
                *.sh)
                    echo "Shell check error: expected #!/bin/sh in ${relative_path}." >&2
                    exit 1
                    ;;
            esac
            ;;
    esac
done <"${candidate_list}"

[ "$#" -gt 0 ] || {
    echo 'Shell check error: no POSIX shell files were discovered.' >&2
    exit 1
}

sh -n "$@"
shellcheck -- "$@"

shell_count=$(wc -l <"${shell_list}" | tr -d ' ')
echo "Shell checks passed: ${shell_count} POSIX scripts."
