#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH='' cd -- "${script_dir}/../../.." && pwd)
fixture_dir="${script_dir}/fixtures"
temporary_root=$(mktemp -d)

cleanup() {
    rm -rf -- "${temporary_root}"
}
trap cleanup EXIT HUP INT TERM

digest=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
log_file="${temporary_root}/docker.log"
state_file="${temporary_root}/docker.state"

cat >"${temporary_root}/prod.secrets.env" <<EOF
GEO_REGISTRY_DATABASE_PASSWORD=test-database-password
GEO_REGISTRY_POSTGRES_ADMIN_PASSWORD=test-admin-password
EOF
cat >"${temporary_root}/release.env" <<EOF
DOCKER_IMAGE_PREFIX=ghcr.io/wolfcharaa/geo-registry
APP_IMAGE_TAG=v0.2.5-linux-amd64
APP_IMAGE_DIGEST=sha256:${digest}
RELEASE_VERSION=v0.2.5
COMPOSE_PROJECT_NAME=geo-registry
EOF
cat >"${temporary_root}/compose.yaml" <<'EOF'
x-app-image: &app-image "ghcr.io/wolfcharaa/geo-registry/app:v0.2.5-linux-amd64"
x-app-environment: &app-environment
  APP_VERSION: v0.2.5
services: {}
EOF

run_prod() {
    PATH="${fixture_dir}:${PATH}" \
    FAKE_DOCKER_STATE="${state_file}" \
    FAKE_DOCKER_LOG="${log_file}" \
    FAKE_DOCKER_CONTEXT="${TEST_DOCKER_CONTEXT-production}" \
    FAKE_DOCKER_CONTEXT_ENDPOINT=ssh://deploy@example.test \
    COMPOSE_ENV_FILE="${temporary_root}/prod.secrets.env" \
    RELEASE_ENV_FILE="${temporary_root}/release.env" \
    PRODUCTION_COMPOSE_MODEL="${temporary_root}/compose.yaml" \
    DEPLOY_TARGET="${TEST_DEPLOY_TARGET-}" \
    DEPLOY_EXPECTED_DOCKER_CONTEXT="${TEST_EXPECTED_CONTEXT-}" \
    DEPLOY_CONFIRM_TARGET="${TEST_CONFIRM_TARGET-}" \
    DEPLOY_SERVICE_SCOPE="${TEST_SERVICE_SCOPE-core}" \
    DEPLOY_DATA_MODE="${TEST_DATA_MODE-update}" \
    DEPLOY_STATE_ROOT="${temporary_root}/deployment-state" \
        "${project_root}/docker/compose/prod.sh" "$@"
}

: >"${log_file}"
run_prod config --quiet
grep -Fq 'compose --env-file' "${log_file}"
grep -Fq ' config --quiet' "${log_file}"

: >"${log_file}"
if missing_output=$(run_prod up --detach app 2>&1); then
    echo 'Production target test failed: mutation without a target was accepted.' >&2
    exit 1
fi
printf '%s\n' "${missing_output}" | grep -Fq 'DEPLOY_TARGET must be an explicit label'
if grep -Fq ' up ' "${log_file}"; then
    echo 'Production target test failed: blocked mutation reached Docker Compose.' >&2
    exit 1
fi

if confirmation_output=$(TEST_DEPLOY_TARGET=moscow-primary \
    TEST_EXPECTED_CONTEXT=production TEST_CONFIRM_TARGET=other-target \
    run_prod up --detach app 2>&1); then
    echo 'Production target test failed: mismatched target confirmation was accepted.' >&2
    exit 1
fi
printf '%s\n' "${confirmation_output}" \
    | grep -Fq 'DEPLOY_CONFIRM_TARGET must exactly repeat DEPLOY_TARGET'

if context_output=$(TEST_DEPLOY_TARGET=moscow-primary \
    TEST_EXPECTED_CONTEXT=production TEST_CONFIRM_TARGET=moscow-primary \
    TEST_DOCKER_CONTEXT=default run_prod up --detach app 2>&1); then
    echo 'Production target test failed: mismatched Docker context was accepted.' >&2
    exit 1
fi
printf '%s\n' "${context_output}" \
    | grep -Fq 'active Docker context is default, expected production'

: >"${log_file}"
authorized_output=$(TEST_DEPLOY_TARGET=moscow-primary \
    TEST_EXPECTED_CONTEXT=production TEST_CONFIRM_TARGET=moscow-primary \
    run_prod up --detach app)
printf '%s\n' "${authorized_output}" | grep -Fq 'Deployment target confirmed'
printf '%s\n' "${authorized_output}" | grep -Fq 'target=moscow-primary'
printf '%s\n' "${authorized_output}" | grep -Fq 'docker_context_endpoint=ssh://example.test'
printf '%s\n' "${authorized_output}" | grep -Fq 'service_scope=core'
printf '%s\n' "${authorized_output}" | grep -Fq 'data_mode=update'
printf '%s\n' "${authorized_output}" | grep -Fq 'Deployment target lock acquired:'
grep -Fq ' up --detach app --no-build --pull never' "${log_file}"

: >"${log_file}"
TEST_DEPLOY_TARGET=moscow-primary \
TEST_EXPECTED_CONTEXT=production \
TEST_CONFIRM_TARGET=moscow-primary \
    run_prod --profile opensearch up --detach opensearch >/dev/null
grep -Fq ' --profile opensearch up --detach opensearch --no-build --pull never' \
    "${log_file}"

if build_output=$(TEST_DEPLOY_TARGET=moscow-primary \
    TEST_EXPECTED_CONTEXT=production TEST_CONFIRM_TARGET=moscow-primary \
    run_prod build app 2>&1); then
    echo 'Production target test failed: production build was accepted.' >&2
    exit 1
fi
printf '%s\n' "${build_output}" | grep -Fq 'build, publication and watch are not available'

if pull_policy_output=$(TEST_DEPLOY_TARGET=moscow-primary \
    TEST_EXPECTED_CONTEXT=production TEST_CONFIRM_TARGET=moscow-primary \
    run_prod up --pull always app 2>&1); then
    echo 'Production target test failed: a pull-policy override was accepted.' >&2
    exit 1
fi
printf '%s\n' "${pull_policy_output}" \
    | grep -Fq 'must not override the frozen pull policy'

if context_override_output=$(run_prod --context other ps 2>&1); then
    echo 'Production target test failed: a context override bypass was accepted.' >&2
    exit 1
fi
printf '%s\n' "${context_override_output}" \
    | grep -Fq 'target, project, Compose files and env files are owned by the wrapper'

if volume_output=$(TEST_DEPLOY_TARGET=moscow-primary \
    TEST_EXPECTED_CONTEXT=production TEST_CONFIRM_TARGET=moscow-primary \
    run_prod down --volumes 2>&1); then
    echo 'Production target test failed: persistent volume removal was accepted.' >&2
    exit 1
fi
printf '%s\n' "${volume_output}" \
    | grep -Fq 'persistent volumes must not be removed'

echo 'Production Compose target confirmation contract test passed.'
