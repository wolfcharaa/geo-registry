#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
# Used by release_resolve_path from release-lib.sh.
# shellcheck disable=SC2034
project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)

# shellcheck disable=SC1091
. "${script_dir}/release-lib.sh"

for command_name in awk chmod mkdir mktemp mv openssl; do
    release_require_command "${command_name}"
done

if [ "$(id -u)" -eq 0 ] || [ -n "${SUDO_USER:-}" ]; then
    release_fail "run as the deployment user, without sudo"
fi

template_file=$(release_resolve_path "${DEPLOY_CONFIG_TEMPLATE:-config/prod.secrets.env.example}")
secrets_file=$(release_resolve_path "${COMPOSE_ENV_FILE:-config/prod.secrets.env}")
release_require_file "${template_file}"

if [ -e "${secrets_file}" ]; then
    echo "Production secrets already exist and were left unchanged: ${secrets_file}"
    exit 0
fi

secrets_directory=$(dirname -- "${secrets_file}")
mkdir -p "${secrets_directory}"
temporary_file=$(mktemp "${secrets_directory}/.prod.secrets.env.XXXXXX")
cleanup() {
    rm -f -- "${temporary_file}"
}
trap cleanup EXIT HUP INT TERM

database_password=$(openssl rand -hex 32)
postgres_admin_password=$(openssl rand -hex 32)

awk \
    -v database_password="${database_password}" \
    -v postgres_admin_password="${postgres_admin_password}" '
        $0 == "GEO_REGISTRY_DATABASE_PASSWORD=generate-me" {
            print "GEO_REGISTRY_DATABASE_PASSWORD=" database_password
            next
        }
        $0 == "GEO_REGISTRY_POSTGRES_ADMIN_PASSWORD=generate-me" {
            print "GEO_REGISTRY_POSTGRES_ADMIN_PASSWORD=" postgres_admin_password
            next
        }
        { print }
    ' "${template_file}" >"${temporary_file}"

chmod 0600 "${temporary_file}"
mv "${temporary_file}" "${secrets_file}"
trap - EXIT HUP INT TERM

echo "Production secrets created: ${secrets_file}"
echo "Non-secret runtime settings remain in compose.yaml and docker/compose/prod/compose.yaml."
echo "Prepare the frozen application image with: make deploy-image"
echo "Then choose prepared restore (make data-restore) or fresh initialization (make data-init)."
