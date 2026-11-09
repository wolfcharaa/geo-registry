#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)

# shellcheck disable=SC1091
. "${script_dir}/release-lib.sh"
# shellcheck disable=SC1091
. "${script_dir}/deployment-lib.sh"
# shellcheck disable=SC1091
. "${project_root}/docker/backup/backup-lib.sh"

for command_name in docker tr; do
    release_require_command "${command_name}"
done

if [ "$(id -u)" -eq 0 ] || [ -n "${SUDO_USER:-}" ]; then
    release_fail "run as the deployment user, without sudo"
fi

secrets_file=$(release_resolve_path "${COMPOSE_ENV_FILE:-config/prod.secrets.env}")
release_require_file "${secrets_file}"
database_password=$(release_env_value GEO_REGISTRY_DATABASE_PASSWORD "${secrets_file}" || true)
case "${database_password}" in
    ''|*[!A-Za-z0-9._~-]*) release_fail "GEO_REGISTRY_DATABASE_PASSWORD contains unsupported characters" ;;
esac

database_name=geo_registry
database_user=geo_registry
admin_user=postgres

release_env_file=$(release_resolve_path \
    "${RELEASE_ENV_FILE:-docker/compose/prod/release.env}")
deployment_require_confirmed_target \
    data-init database fresh "${release_env_file}"
deployment_acquire_target_lock \
    data-init database fresh "${release_env_file}"

COMPOSE_ENV_FILE="${secrets_file}" "${project_root}/docker/compose/prod.sh" \
    stop app worker-address worker-runtime >/dev/null 2>&1 || true

deployment_prepare_postgres_image "${release_env_file}" "${secrets_file}"
COMPOSE_ENV_FILE="${secrets_file}" "${project_root}/docker/compose/prod.sh" \
    up --detach --wait postgres
container=$(COMPOSE_ENV_FILE="${secrets_file}" \
    "${project_root}/docker/compose/prod.sh" ps --quiet postgres)
backup_validate_container "${container}"

database_exists=$(docker exec "${container}" \
    psql --no-psqlrc --tuples-only --no-align --set=ON_ERROR_STOP=1 \
    --username "${admin_user}" --dbname postgres \
    --command "SELECT count(*) FROM pg_database WHERE datname = '${database_name}';" \
    | tr -d '[:space:]')
[ "${database_exists}" = 0 ] \
    || release_fail "database already exists; fresh initialization is refused: ${database_name}"

role_exists=$(docker exec "${container}" \
    psql --no-psqlrc --tuples-only --no-align --set=ON_ERROR_STOP=1 \
    --username "${admin_user}" --dbname postgres \
    --command "SELECT count(*) FROM pg_roles WHERE rolname = '${database_user}';" \
    | tr -d '[:space:]')
case "${role_exists}" in
    0)
        docker exec "${container}" psql --no-psqlrc --set=ON_ERROR_STOP=1 \
            --username "${admin_user}" --dbname postgres \
            --command "CREATE ROLE ${database_user} LOGIN SUPERUSER PASSWORD '${database_password}';"
        ;;
    1)
        docker exec "${container}" psql --no-psqlrc --set=ON_ERROR_STOP=1 \
            --username "${admin_user}" --dbname postgres \
            --command "ALTER ROLE ${database_user} LOGIN SUPERUSER PASSWORD '${database_password}';"
        ;;
    *) release_fail "cannot determine database owner role state" ;;
esac

demote_owner() {
    docker exec "${container}" psql --no-psqlrc --set=ON_ERROR_STOP=1 \
        --username "${admin_user}" --dbname postgres \
        --command "ALTER ROLE ${database_user} NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION;" \
        >/dev/null 2>&1 \
        || echo "Warning: failed to remove temporary elevated privileges from ${database_user}." >&2
}
cleanup_owner() {
    cleanup_status=$?
    trap - EXIT HUP INT TERM
    demote_owner
    exit "${cleanup_status}"
}
trap cleanup_owner EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

docker exec "${container}" createdb --no-password \
    --username "${admin_user}" --owner "${database_user}" \
    --template template0 "${database_name}"

COMPOSE_ENV_FILE="${secrets_file}" "${project_root}/docker/compose/prod.sh" \
    run --rm --no-deps maintenance bin/app migrate:up --force-yes

demote_owner
trap - EXIT HUP INT TERM
echo "Fresh application database initialized: ${database_name}"
