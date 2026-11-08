#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)

# shellcheck disable=SC1091
. "${script_dir}/release-lib.sh"

for command_name in awk docker grep ss; do
    release_require_command "${command_name}"
done

if [ "$(id -u)" -eq 0 ] || [ -n "${SUDO_USER:-}" ]; then
    release_fail "run as the deployment user, without sudo"
fi

secrets_file=$(release_resolve_path "${COMPOSE_ENV_FILE:-config/prod.secrets.env}")
release_require_file "${secrets_file}"
next_command=${DEPLOY_PORT_NEXT_COMMAND:-make deploy-start DOCKER_PLATFORM=linux/amd64}
binding=$(COMPOSE_ENV_FILE="${secrets_file}" "${script_dir}/deployment-http-binding.sh")
IFS='|' read -r host_ip published_port target_port <<EOF
${binding}
EOF

echo "Deployment HTTP port preflight"
echo "Configured binding: ${host_ip}:${published_port} -> app:${target_port}/tcp"
case "${host_ip}" in
    0.0.0.0|::)
        echo "Warning: this binding publishes Geo Registry on every host interface."
        echo "Prefer an exact private/LAN IP and restrict TCP/${published_port} with a firewall allow-list."
        ;;
esac

listeners=$(ss -H -ltn "sport = :${published_port}")
if [ -z "${listeners}" ]; then
    echo "State: free"
    echo "The configured HTTP port can be published."
    echo "Next action: no port action is required; continue the invoking deployment command."
    echo "Standalone preflight: run ${next_command}."
    exit 0
fi

managed_app=$(COMPOSE_ENV_FILE="${secrets_file}" \
    "${project_root}/docker/compose/prod.sh" ps --quiet app)
if [ -n "${managed_app}" ] \
    && [ "$(docker inspect --format '{{.State.Running}}' "${managed_app}" 2>/dev/null || true)" = true ]; then
    managed_bindings=$(docker port "${managed_app}" "${target_port}/tcp" 2>/dev/null || true)
    managed_name=$(docker inspect --format '{{.Name}}' "${managed_app}" | awk '{sub("^/", ""); print}')
    if printf '%s\n' "${managed_bindings}" \
        | grep -Fqx -e "${host_ip}:${published_port}" -e "[${host_ip}]:${published_port}"; then
        echo "State: occupied-by-managed-app"
        echo "Container: ${managed_name}"
        echo "The release workflow may recreate this managed application container."
        echo "Next action: do not stop it manually; continue the invoking deployment command."
        echo "Standalone preflight: run ${next_command}."
        exit 0
    fi
    listeners_owned_by_managed=1
    while IFS= read -r listener; do
        listener_address=$(printf '%s\n' "${listener}" | awk '{print $4}')
        if ! printf '%s\n' "${managed_bindings}" | grep -Fqx "${listener_address}"; then
            listeners_owned_by_managed=0
            break
        fi
    done <<EOF
${listeners}
EOF
    if [ "${listeners_owned_by_managed}" = 1 ] \
        && printf '%s\n' "${managed_bindings}" \
        | awk -F: -v port="${published_port}" '$NF == port {found = 1} END {exit found ? 0 : 1}'; then
        echo "State: occupied-by-managed-app-pending-rebind"
        echo "Container: ${managed_name}"
        echo "Current binding: ${managed_bindings}"
        echo "The release workflow may recreate this managed container with the configured host IP."
        echo "Next action: do not stop it manually; continue the invoking deployment command."
        echo "Standalone preflight: run ${next_command}."
        exit 0
    fi
fi

echo "State: conflict" >&2
echo "Host listeners:" >&2
ss -H -ltnp "sport = :${published_port}" >&2 || printf '%s\n' "${listeners}" >&2
docker_owners=$(docker ps \
    --filter "publish=${published_port}" \
    --format 'container={{.Names}} image={{.Image}} ports={{.Ports}}')
if [ -n "${docker_owners}" ]; then
    echo "Docker publishers:" >&2
    printf '%s\n' "${docker_owners}" >&2
fi
echo "Next action:" >&2
echo "1. If the listener belongs to another service, keep that service and choose another host IP/port in compose.override.yaml, or stop it intentionally." >&2
echo "2. Rerun: make deploy-port-preflight" >&2
echo "3. After State: free or occupied-by-managed-app*, run: ${next_command}" >&2
release_fail "configured HTTP binding is already in use: ${host_ip}:${published_port}"
