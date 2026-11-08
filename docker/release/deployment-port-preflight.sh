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
binding=$(COMPOSE_ENV_FILE="${secrets_file}" "${script_dir}/deployment-http-binding.sh")
IFS='|' read -r host_ip published_port target_port <<EOF
${binding}
EOF

echo "Deployment HTTP port preflight"
echo "Configured binding: ${host_ip}:${published_port} -> app:${target_port}/tcp"

listeners=$(ss -H -ltn "sport = :${published_port}")
if [ -z "${listeners}" ]; then
    echo "State: free"
    echo "The configured HTTP port can be published."
    exit 0
fi

managed_app=$(COMPOSE_ENV_FILE="${secrets_file}" \
    "${project_root}/docker/compose/prod.sh" ps --quiet app)
if [ -n "${managed_app}" ] \
    && [ "$(docker inspect --format '{{.State.Running}}' "${managed_app}" 2>/dev/null || true)" = true ]; then
    managed_bindings=$(docker port "${managed_app}" "${target_port}/tcp" 2>/dev/null || true)
    if printf '%s\n' "${managed_bindings}" \
        | grep -Fqx -e "${host_ip}:${published_port}" -e "[${host_ip}]:${published_port}"; then
        echo "State: occupied-by-managed-app"
        echo "Container: $(docker inspect --format '{{.Name}}' "${managed_app}" | awk '{sub("^/", ""); print}')"
        echo "The release workflow may recreate this managed application container."
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
echo "Resolve the owner or change services.app.ports in compose.override.yaml, then rerun make deploy-port-preflight." >&2
release_fail "configured HTTP binding is already in use: ${host_ip}:${published_port}"
