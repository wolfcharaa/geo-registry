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

log_file="${temporary_root}/docker.log"
state_file="${temporary_root}/docker.state"
secrets_file="${temporary_root}/prod.secrets.env"
printf '%s\n' \
    'GEO_REGISTRY_DATABASE_PASSWORD=test-database-password' \
    'GEO_REGISTRY_POSTGRES_ADMIN_PASSWORD=test-admin-password' \
    >"${secrets_file}"

tagged_image=ghcr.io/wolfcharaa/geo-registry/app:v0.2.5-linux-amd64

run_doctor() {
    test_target=${TEST_DEPLOY_TARGET-moscow-primary}
    test_context=${TEST_DOCKER_CONTEXT-production}
    test_expected_context=${TEST_EXPECTED_DOCKER_CONTEXT-production}
    test_engine_available=${TEST_DOCKER_ENGINE_AVAILABLE-1}
    PATH="${fixture_dir}:${PATH}" \
    FAKE_DOCKER_STATE="${state_file}" \
    FAKE_DOCKER_LOG="${log_file}" \
    FAKE_DOCKER_CONTEXT="${test_context}" \
    FAKE_DOCKER_CONTEXT_ENDPOINT=ssh://deploy@example.test \
    FAKE_DOCKER_ENGINE_AVAILABLE="${test_engine_available}" \
    COMPOSE_ENV_FILE="${secrets_file}" \
    DEPLOY_TARGET="${test_target}" \
    DEPLOY_EXPECTED_DOCKER_CONTEXT="${test_expected_context}" \
        "${project_root}/docker/release/deploy-doctor.sh"
}

run_plan() {
    test_target=${TEST_DEPLOY_TARGET-moscow-primary}
    test_context=${TEST_DOCKER_CONTEXT-production}
    test_expected_context=${TEST_EXPECTED_DOCKER_CONTEXT-production}
    test_engine_available=${TEST_DOCKER_ENGINE_AVAILABLE-1}
    test_data_mode=${TEST_DEPLOY_DATA_MODE-inspect}
    test_service_scope=${TEST_DEPLOY_SERVICE_SCOPE-core}
    test_release_env=${TEST_RELEASE_ENV_FILE-}
    test_artifact_env=${TEST_RELEASE_ARTIFACT_ENV_FILE-}
    test_compose_model=${TEST_PRODUCTION_COMPOSE_MODEL-}
    test_current_image=${TEST_CURRENT_IMAGE_REFERENCE-${tagged_image}}
    PATH="${fixture_dir}:${PATH}" \
    FAKE_DOCKER_STATE="${state_file}" \
    FAKE_DOCKER_LOG="${log_file}" \
    FAKE_DOCKER_CONTEXT="${test_context}" \
    FAKE_DOCKER_CONTEXT_ENDPOINT=ssh://deploy@example.test \
    FAKE_DOCKER_ENGINE_AVAILABLE="${test_engine_available}" \
    FAKE_DOCKER_EXACT_LOADED=1 \
    FAKE_DOCKER_APP_CONTAINER=geo-registry-app-test \
    FAKE_DOCKER_CURRENT_IMAGE_REFERENCE="${test_current_image}" \
    FAKE_DOCKER_CURRENT_VERSION=v0.2.5 \
    COMPOSE_ENV_FILE="${secrets_file}" \
    DEPLOY_TARGET="${test_target}" \
    DEPLOY_EXPECTED_DOCKER_CONTEXT="${test_expected_context}" \
    DEPLOY_DATA_MODE="${test_data_mode}" \
    DEPLOY_SERVICE_SCOPE="${test_service_scope}" \
    RELEASE_ENV_FILE="${test_release_env}" \
    RELEASE_ARTIFACT_ENV_FILE="${test_artifact_env}" \
    PRODUCTION_COMPOSE_MODEL="${test_compose_model}" \
        "${project_root}/docker/release/deploy-plan.sh"
}

: >"${log_file}"
doctor_output=$(run_doctor)
printf '%s\n' "${doctor_output}" | grep -Fq 'Deployment doctor (read-only)'
printf '%s\n' "${doctor_output}" | grep -Fq 'status=ready'
printf '%s\n' "${doctor_output}" | grep -Fq 'target=moscow-primary'
printf '%s\n' "${doctor_output}" | grep -Fq 'docker_context=production'
printf '%s\n' "${doctor_output}" | grep -Fq 'docker_context_endpoint=ssh://example.test'
printf '%s\n' "${doctor_output}" | grep -Fq 'host_platform=linux/amd64'
printf '%s\n' "${doctor_output}" | grep -Fq 'external_mutations=none'

: >"${log_file}"
plan_output=$(run_plan)
printf '%s\n' "${plan_output}" | grep -Fq 'Deployment plan (read-only)'
printf '%s\n' "${plan_output}" | grep -Fq 'doctor_status=ready'
printf '%s\n' "${plan_output}" | grep -Fq 'status=ready'
printf '%s\n' "${plan_output}" | grep -Fq 'data_mode=inspect'
printf '%s\n' "${plan_output}" | grep -Fq 'service_scope=core'
printf '%s\n' "${plan_output}" | grep -Fq 'desired_release=v0.2.5'
printf '%s\n' "${plan_output}" | grep -Fq "desired_tagged_image=${tagged_image}"
printf '%s\n' "${plan_output}" | grep -Eq 'rendered_config_checksum=sha256:[0-9a-f]{64}'
printf '%s\n' "${plan_output}" | grep -Fq 'current_relation=desired'
printf '%s\n' "${plan_output}" | grep -Fq 'backup_gate=not-evaluated'
printf '%s\n' "${plan_output}" | grep -Fq 'external_mutations=none'
if printf '%s\n' "${plan_output}" | grep -Fq 'test-database-password'; then
    echo 'Deployment plan test failed: a secret value reached plan output.' >&2
    exit 1
fi
if grep -Eq '^(load|pull|push|run|exec) ' "${log_file}" \
    || grep -Eq '^compose .* (up|stop|run|rm|down)( |$)' "${log_file}"; then
    echo 'Deployment plan test failed: read-only inspection attempted a mutation.' >&2
    exit 1
fi

prepared_source_env="${temporary_root}/prepared-release.env"
prepared_artifact_env="${temporary_root}/release-artifact.env"
prepared_compose_model="${temporary_root}/compose.yaml"
prepared_tagged_image=registry.example/geo-registry/app:v9.9.9-linux-amd64
prepared_digest=cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc
prepared_image_id=sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
prepared_source_oid=$(git -C "${project_root}" rev-parse HEAD)
cat >"${prepared_source_env}" <<EOF
RELEASE_IDENTITY_FORMAT=2
DOCKER_IMAGE_PREFIX=registry.example/geo-registry
APP_IMAGE_TAG=v9.9.9-linux-amd64
RELEASE_VERSION=v9.9.9
RELEASE_PLATFORM=linux/amd64
COMPOSE_PROJECT_NAME=geo-registry
EOF
cat >"${prepared_compose_model}" <<EOF
x-app-image: &app-image "${prepared_tagged_image}"
x-app-environment: &app-environment
  APP_VERSION: v9.9.9
services: {}
EOF
# shellcheck disable=SC1091
. "${project_root}/docker/release/release-lib.sh"
prepared_config_revision=$(release_config_revision \
    "${prepared_compose_model}" "${prepared_source_env}")
cat >"${prepared_artifact_env}" <<EOF
RELEASE_ARTIFACT_FORMAT=1
RELEASE_VERSION=v9.9.9
RELEASE_SOURCE_OID=${prepared_source_oid}
RELEASE_CONFIG_REVISION=${prepared_config_revision}
DOCKER_PLATFORM=linux/amd64
DOCKER_IMAGE_PREFIX=registry.example/geo-registry
APP_IMAGE_TAG=v9.9.9-linux-amd64
APP_IMAGE_DIGEST=sha256:${prepared_digest}
APP_IMAGE_ID=${prepared_image_id}
COMPOSE_PROJECT_NAME=geo-registry
EOF

prepared_output=$(TEST_RELEASE_ENV_FILE="${prepared_source_env}" \
    TEST_RELEASE_ARTIFACT_ENV_FILE="${prepared_artifact_env}" \
    TEST_PRODUCTION_COMPOSE_MODEL="${prepared_compose_model}" \
    TEST_CURRENT_IMAGE_REFERENCE="${prepared_tagged_image}" \
    run_plan)
printf '%s\n' "${prepared_output}" | grep -Fq 'status=ready'
printf '%s\n' "${prepared_output}" | grep -Fq 'source_identity_format=2'
printf '%s\n' "${prepared_output}" \
    | grep -Fq "desired_source_oid=${prepared_source_oid}"
printf '%s\n' "${prepared_output}" \
    | grep -Fq "desired_config_revision=${prepared_config_revision}"
printf '%s\n' "${prepared_output}" \
    | grep -Fq 'desired_identity_assurance=source-config-digest-image-id'

mismatched_artifact_env="${temporary_root}/release-artifact-mismatch.env"
sed "s/RELEASE_CONFIG_REVISION=.*/RELEASE_CONFIG_REVISION=sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd/" \
    "${prepared_artifact_env}" >"${mismatched_artifact_env}"
if mismatch_identity_output=$(TEST_RELEASE_ENV_FILE="${prepared_source_env}" \
    TEST_RELEASE_ARTIFACT_ENV_FILE="${mismatched_artifact_env}" \
    TEST_PRODUCTION_COMPOSE_MODEL="${prepared_compose_model}" \
    run_plan 2>&1); then
    echo 'Deployment plan test failed: a mismatched artifact identity was accepted.' >&2
    exit 1
fi
printf '%s\n' "${mismatch_identity_output}" | grep -Fq 'status=blocked'
printf '%s\n' "${mismatch_identity_output}" | grep -Fq 'artifact-identity-mismatch'

if missing_target_output=$(TEST_DEPLOY_TARGET='' run_doctor 2>&1); then
    echo 'Deployment doctor test failed: an implicit target was accepted.' >&2
    exit 1
fi
printf '%s\n' "${missing_target_output}" | grep -Fq 'status=blocked'
printf '%s\n' "${missing_target_output}" | grep -Fq 'target-missing'

if mismatch_output=$(TEST_DOCKER_CONTEXT=default run_doctor 2>&1); then
    echo 'Deployment doctor test failed: a Docker context mismatch was accepted.' >&2
    exit 1
fi
printf '%s\n' "${mismatch_output}" | grep -Fq 'docker_context_state=mismatch'
printf '%s\n' "${mismatch_output}" | grep -Fq 'docker-context-mismatch'

if engine_output=$(TEST_DOCKER_ENGINE_AVAILABLE=0 run_plan 2>&1); then
    echo 'Deployment plan test failed: an unavailable Docker engine was accepted.' >&2
    exit 1
fi
printf '%s\n' "${engine_output}" | grep -Fq 'doctor_engine_state=unavailable'
printf '%s\n' "${engine_output}" | grep -Fq 'status=blocked'

if update_output=$(TEST_DEPLOY_DATA_MODE=update run_plan 2>&1); then
    echo 'Deployment plan test failed: update without a verified backup was accepted.' >&2
    exit 1
fi
printf '%s\n' "${update_output}" | grep -Fq 'backup_gate=missing'
printf '%s\n' "${update_output}" | grep -Fq 'backup-required'
printf '%s\n' "${update_output}" \
    | grep -Fq 'no pull, load, container, data or evidence mutation was attempted'

echo 'Deployment doctor and read-only plan contract test passed.'
