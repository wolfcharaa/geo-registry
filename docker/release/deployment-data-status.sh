#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)

# shellcheck disable=SC1091
. "${script_dir}/release-lib.sh"

for command_name in docker sed tr; do
    release_require_command "${command_name}"
done

if [ "$(id -u)" -eq 0 ] || [ -n "${SUDO_USER:-}" ]; then
    release_fail "run as the deployment user, without sudo"
fi

secrets_file=$(release_resolve_path "${COMPOSE_ENV_FILE:-config/prod.secrets.env}")
release_require_file "${secrets_file}"
database_name=geo_registry
database_user=geo_registry
admin_user=postgres
require_ready=${DEPLOY_DATA_REQUIRE_READY:-0}
case "${require_ready}" in
    0|1) ;;
    *) release_fail "DEPLOY_DATA_REQUIRE_READY must be 0 or 1" ;;
esac

container=$(COMPOSE_ENV_FILE="${secrets_file}" \
    "${project_root}/docker/compose/prod.sh" ps --quiet postgres)

echo "Deployment data status"
if [ -z "${container}" ] \
    || [ "$(docker inspect --format '{{.State.Running}}' "${container}" 2>/dev/null || true)" != true ]; then
    echo "State: postgres-not-running"
    echo "Next action: make data-restore or make data-init"
    [ "${require_ready}" = 0 ] || exit 1
    exit 0
fi

container_name=$(docker inspect --format '{{.Name}}' "${container}" | sed 's#^/##')
echo "PostgreSQL container: ${container_name}"

status_query() {
    status_database=$1
    status_sql=$2
    docker exec "${container}" \
        psql --no-psqlrc --tuples-only --no-align --set=ON_ERROR_STOP=1 \
        --username "${admin_user}" --dbname "${status_database}" --command "${status_sql}"
}

database_exists=$(status_query postgres \
    "SELECT count(*) FROM pg_database WHERE datname = '${database_name}';" | tr -d '[:space:]')
if [ "${database_exists}" = 0 ]; then
    echo "Database: ${database_name}"
    echo "State: database-absent"
    echo "Next action: make data-restore or make data-init"
    [ "${require_ready}" = 0 ] || exit 1
    exit 0
fi
[ "${database_exists}" = 1 ] || release_fail "cannot determine managed database state"

database_summary=$(status_query postgres "
SELECT pg_size_pretty(pg_database_size(d.oid))
    || '|' || pg_get_userbyid(d.datdba)
    || '|' || (
        SELECT count(*)
        FROM pg_stat_activity
        WHERE datname = '${database_name}'
          AND application_name = 'pg_restore'
    )
FROM pg_database AS d
WHERE d.datname = '${database_name}';
")
IFS='|' read -r database_size database_owner restore_sessions <<EOF
${database_summary}
EOF

role_summary=$(status_query postgres "
SELECT rolsuper::int
    || '|' || rolcreatedb::int
    || '|' || rolcreaterole::int
    || '|' || rolreplication::int
FROM pg_roles
WHERE rolname = '${database_user}';
")
[ -n "${role_summary}" ] || role_summary='missing|missing|missing|missing'
IFS='|' read -r role_super role_createdb role_createrole role_replication <<EOF
${role_summary}
EOF

integrity_summary=$(status_query "${database_name}" "
SELECT (
        SELECT count(*)
        FROM pg_class AS c
        JOIN pg_namespace AS n ON n.oid = c.relnamespace
        WHERE n.nspname NOT IN ('pg_catalog', 'information_schema')
          AND c.relkind IN ('r', 'p')
    )
    || '|' || (SELECT count(*) FROM pg_index WHERE NOT indisvalid OR NOT indisready)
    || '|' || (SELECT count(*) FROM pg_constraint WHERE NOT convalidated);
")
IFS='|' read -r relations invalid_indexes unvalidated_constraints <<EOF
${integrity_summary}
EOF

echo "Database: ${database_name}"
echo "Size: ${database_size}"
echo "Owner: ${database_owner}"
echo "Relations: ${relations}"
echo "Invalid indexes: ${invalid_indexes}"
echo "Unvalidated constraints: ${unvalidated_constraints}"
echo "Owner privileges: superuser=${role_super} createdb=${role_createdb} createrole=${role_createrole} replication=${role_replication}"

if [ "${restore_sessions}" -gt 0 ]; then
    echo "State: restore-active"
    status_query postgres "
SELECT 'pid=' || pid
    || ' state=' || state
    || ' elapsed=' || (clock_timestamp() - query_start)
    || ' wait=' || COALESCE(wait_event_type || '/' || wait_event, 'cpu')
FROM pg_stat_activity
WHERE datname = '${database_name}'
  AND application_name = 'pg_restore'
ORDER BY query_start;
"
    echo "Next action: wait; do not rerun restore or stop PostgreSQL"
    [ "${require_ready}" = 0 ] || exit 1
    exit 0
fi

if [ "${invalid_indexes}" -gt 0 ] || [ "${unvalidated_constraints}" -gt 0 ]; then
    echo "State: present-incomplete-or-invalid"
    echo "Next action: preserve the database and PostgreSQL logs; do not rerun restore or deploy-start"
    exit 1
fi

if [ "${database_owner}" != "${database_user}" ] \
    || [ "${role_super}" != 0 ] \
    || [ "${role_createdb}" != 0 ] \
    || [ "${role_createrole}" != 0 ] \
    || [ "${role_replication}" != 0 ]; then
    echo "State: owner-security-gate-failed"
    echo "Next action: preserve the database and inspect restore cleanup; do not deploy-start"
    exit 1
fi

echo "State: present-needs-application-validation"
echo "Next action: make deploy-start; it runs migration, GAR and HTTP gates before activation"
