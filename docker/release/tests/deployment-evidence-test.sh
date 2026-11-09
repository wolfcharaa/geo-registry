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
state_root="${temporary_root}/deployment-state"
target_state="${state_root}/moscow-primary"

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

evidence_environment() {
    PATH="${fixture_dir}:${PATH}"
    export PATH
    FAKE_DOCKER_STATE="${temporary_root}/docker.state"
    FAKE_DOCKER_LOG="${temporary_root}/docker.log"
    FAKE_DOCKER_APP_CONTAINER=geo-registry-app-test
    FAKE_DOCKER_CURRENT_IMAGE_REFERENCE="ghcr.io/wolfcharaa/geo-registry/app@sha256:${digest}"
    COMPOSE_ENV_FILE="${temporary_root}/prod.secrets.env"
    RELEASE_ENV_FILE="${temporary_root}/release.env"
    PRODUCTION_COMPOSE_MODEL="${temporary_root}/compose.yaml"
    DEPLOY_TARGET=moscow-primary
    DEPLOY_EXPECTED_DOCKER_CONTEXT=production
    DEPLOY_CONFIRM_TARGET=moscow-primary
    DEPLOY_STATE_ROOT="${state_root}"
    export FAKE_DOCKER_STATE FAKE_DOCKER_LOG FAKE_DOCKER_APP_CONTAINER
    export FAKE_DOCKER_CURRENT_IMAGE_REFERENCE COMPOSE_ENV_FILE RELEASE_ENV_FILE
    export PRODUCTION_COMPOSE_MODEL DEPLOY_TARGET DEPLOY_EXPECTED_DOCKER_CONTEXT
    export DEPLOY_CONFIRM_TARGET DEPLOY_STATE_ROOT
}

record_success() (
    operation=$1
    evidence_environment
    # shellcheck disable=SC1091
    . "${project_root}/docker/release/release-lib.sh"
    # shellcheck disable=SC1091
    . "${project_root}/docker/release/deployment-lib.sh"
    deployment_require_confirmed_target \
        "${operation}" application update "${temporary_root}/release.env"
    deployment_acquire_target_lock \
        "${operation}" application update "${temporary_root}/release.env"
    deployment_evidence_begin \
        "${operation}" application update "${temporary_root}/release.env"
    deployment_evidence_update \
        artifact_status verified smoke_status passed
    if [ "${operation}" = evidence-first ]; then
        if unknown_evidence_output=$(deployment_evidence_update \
            unexpected_evidence_field value 2>&1); then
            echo 'Deployment evidence test failed: an unknown evidence key was accepted.' >&2
            exit 1
        fi
        printf '%s\n' "${unknown_evidence_output}" \
            | grep -Fq 'unsupported deployment evidence key: unexpected_evidence_field'
    fi
    deployment_evidence_finish desired-active
)

record_failure() (
    operation=$1
    mutation_started=$2
    recovery_branch=$3
    evidence_environment
    # shellcheck disable=SC1091
    . "${project_root}/docker/release/release-lib.sh"
    # shellcheck disable=SC1091
    . "${project_root}/docker/release/deployment-lib.sh"
    deployment_require_confirmed_target \
        "${operation}" application update "${temporary_root}/release.env"
    deployment_acquire_target_lock \
        "${operation}" application update "${temporary_root}/release.env"
    deployment_evidence_begin \
        "${operation}" application update "${temporary_root}/release.env"
    deployment_evidence_fail 42 smoke "${mutation_started}" "${recovery_branch}"
)

record_success evidence-first >/dev/null
grep -Fq 'state=success' "${target_state}/current.env"
grep -Fq 'operation=evidence-first' "${target_state}/current.env"

record_success evidence-second >/dev/null
grep -Fq 'operation=evidence-second' "${target_state}/current.env"
grep -Fq 'operation=evidence-first' "${target_state}/previous.env"

record_failure evidence-safe-abort 0 none >/dev/null 2>&1
[ ! -e "${target_state}/partial.env" ]
safe_abort_file=$(grep -l '^operation=evidence-safe-abort$' \
    "${target_state}"/operations/*)
grep -Fq 'state=aborted' "${safe_abort_file}"
grep -Fq 'final_state=safe-abort' "${safe_abort_file}"

record_failure evidence-partial 1 roll-forward >/dev/null 2>&1
grep -Fq 'state=partial' "${target_state}/partial.env"
grep -Fq 'failed_step=smoke' "${target_state}/partial.env"
grep -Fq 'recovery_branch=roll-forward' "${target_state}/partial.env"

if blocked_output=$(record_success evidence-must-block 2>&1); then
    echo 'Deployment evidence test failed: partial state did not block mutation.' >&2
    exit 1
fi
printf '%s\n' "${blocked_output}" \
    | grep -Fq 'requires an explicit recovery handover'

evidence_environment
handover_output=$(DEPLOY_RECOVERY_BRANCH=roll-forward \
    DEPLOY_HANDOVER_CONFIRM=handover:moscow-primary:roll-forward \
    "${project_root}/docker/release/deployment-handover.sh")
printf '%s\n' "${handover_output}" \
    | grep -Fq 'Recovery branch: roll-forward'
[ ! -e "${target_state}/partial.env" ]
handover_file=$(grep -l '^recovery_branch=roll-forward$' \
    "${target_state}"/handovers/*)
grep -Fq 'state=handed-over' "${handover_file}"
grep -Fq 'final_state=recovery-handover' "${handover_file}"

record_success evidence-after-handover >/dev/null
grep -Fq 'operation=evidence-after-handover' "${target_state}/current.env"

echo 'Deployment evidence and recovery handover contract test passed.'
