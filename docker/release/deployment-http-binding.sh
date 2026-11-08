#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)

# shellcheck disable=SC1091
. "${script_dir}/release-lib.sh"

for command_name in awk docker; do
    release_require_command "${command_name}"
done

if [ "$(id -u)" -eq 0 ] || [ -n "${SUDO_USER:-}" ]; then
    release_fail "run as the deployment user, without sudo"
fi

secrets_file=$(release_resolve_path "${COMPOSE_ENV_FILE:-config/prod.secrets.env}")
release_require_file "${secrets_file}"

binding=$(
    COMPOSE_ENV_FILE="${secrets_file}" \
        "${project_root}/docker/compose/prod.sh" config --format json \
        | awk '
            /^    "app": \{$/ {
                in_app = 1
                next
            }
            in_app && /^      "ports": \[$/ {
                in_ports = 1
                next
            }
            in_ports && /^      \]/ {
                exit
            }
            in_ports && /"host_ip":/ {
                value = $0
                sub(/^[^:]+:[[:space:]]*"/, "", value)
                sub(/"[,]?$/, "", value)
                host = value
                next
            }
            in_ports && /"target":/ {
                value = $0
                sub(/^[^:]+:[[:space:]]*/, "", value)
                sub(/[,]$/, "", value)
                target = value
                next
            }
            in_ports && /"published":/ {
                value = $0
                sub(/^[^:]+:[[:space:]]*"?/, "", value)
                sub(/"?[,]$/, "", value)
                published = value
                next
            }
            in_ports && /"protocol":/ {
                value = $0
                sub(/^[^:]+:[[:space:]]*"/, "", value)
                sub(/"[,]?$/, "", value)
                protocol = value
                next
            }
            in_ports && /^        }[,]?$/ {
                if (target == "8080" && protocol == "tcp" && published != "") {
                    if (host == "") {
                        host = "0.0.0.0"
                    }
                    print host "|" published "|" target
                }
                host = ""
                target = ""
                published = ""
                protocol = ""
            }
        '
)

binding_count=$(printf '%s\n' "${binding}" | awk 'NF > 0 {count++} END {print count + 0}')
[ "${binding_count}" -eq 1 ] \
    || release_fail "production Compose must publish app target 8080/tcp exactly once"

IFS='|' read -r host_ip published_port target_port <<EOF
${binding}
EOF
case "${host_ip}" in
    ''|*[!0-9A-Fa-f:.]*) release_fail "resolved HTTP host IP is invalid" ;;
esac
case "${published_port}" in
    ''|*[!0-9]*) release_fail "resolved HTTP published port is invalid" ;;
esac
case "${target_port}" in
    ''|*[!0-9]*) release_fail "resolved HTTP target port is invalid" ;;
esac
if [ "${published_port}" -lt 1 ] || [ "${published_port}" -gt 65535 ]; then
    release_fail "resolved HTTP published port is outside 1..65535"
fi
[ "${target_port}" = 8080 ] \
    || release_fail "resolved HTTP target port must be 8080"

printf '%s|%s|%s\n' "${host_ip}" "${published_port}" "${target_port}"
