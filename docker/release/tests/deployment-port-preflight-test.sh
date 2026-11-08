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

cat >"${temporary_root}/prod.secrets.env" <<EOF
GEO_REGISTRY_DATABASE_PASSWORD=test-database-password
GEO_REGISTRY_POSTGRES_ADMIN_PASSWORD=test-admin-password
EOF

cat >"${temporary_root}/release.env" <<EOF
DOCKER_IMAGE_PREFIX=ghcr.io/wolfcharaa/geo-registry
APP_IMAGE_TAG=v0.2.0-linux-amd64
APP_IMAGE_DIGEST=sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
RELEASE_VERSION=v0.2.0
COMPOSE_PROJECT_NAME=geo-registry
EOF

cat >"${temporary_root}/compose-model.yaml" <<EOF
x-app-image: &app-image "ghcr.io/wolfcharaa/geo-registry/app:v0.2.0-linux-amd64"
x-app-environment: &app-environment
  APP_VERSION: v0.2.0
services: {}
EOF

cat >"${temporary_root}/compose.json" <<'EOF'
{
  "name": "geo-registry",
  "services": {
    "app": {
      "image": "example/app",
      "ports": [
        {
          "mode": "ingress",
          "host_ip": "127.0.0.1",
          "target": 8080,
          "published": "18080",
          "protocol": "tcp"
        }
      ]
    }
  }
}
EOF

binding_output=$(
    PATH="${fixture_dir}:${PATH}" \
    FAKE_DOCKER_STATE="${temporary_root}/docker.state" \
    FAKE_DOCKER_COMPOSE_CONFIG_FILE="${temporary_root}/compose.json" \
    COMPOSE_ENV_FILE="${temporary_root}/prod.secrets.env" \
    RELEASE_ENV_FILE="${temporary_root}/release.env" \
    PRODUCTION_COMPOSE_MODEL="${temporary_root}/compose-model.yaml" \
        "${project_root}/docker/release/deployment-http-binding.sh"
)
[ "${binding_output}" = '127.0.0.1|18080|8080' ] || {
    echo "Deployment port preflight test failed: unexpected binding ${binding_output}." >&2
    exit 1
}

free_output=$(
    PATH="${fixture_dir}:${PATH}" \
    FAKE_DOCKER_STATE="${temporary_root}/docker.state" \
    FAKE_DOCKER_COMPOSE_CONFIG_FILE="${temporary_root}/compose.json" \
    COMPOSE_ENV_FILE="${temporary_root}/prod.secrets.env" \
    RELEASE_ENV_FILE="${temporary_root}/release.env" \
    PRODUCTION_COMPOSE_MODEL="${temporary_root}/compose-model.yaml" \
        "${project_root}/docker/release/deployment-port-preflight.sh"
)
printf '%s\n' "${free_output}" | grep -Fq 'Configured binding: 127.0.0.1:18080 -> app:8080/tcp'
printf '%s\n' "${free_output}" | grep -Fq 'State: free'

if conflict_output=$(
    PATH="${fixture_dir}:${PATH}" \
    FAKE_DOCKER_STATE="${temporary_root}/docker.state" \
    FAKE_DOCKER_COMPOSE_CONFIG_FILE="${temporary_root}/compose.json" \
    FAKE_DOCKER_PORT_OWNERS='container=other-http image=example/http ports=127.0.0.1:18080->80/tcp' \
    FAKE_SS_LISTENERS='LISTEN 0 4096 127.0.0.1:18080 0.0.0.0:*' \
    COMPOSE_ENV_FILE="${temporary_root}/prod.secrets.env" \
    RELEASE_ENV_FILE="${temporary_root}/release.env" \
    PRODUCTION_COMPOSE_MODEL="${temporary_root}/compose-model.yaml" \
        "${project_root}/docker/release/deployment-port-preflight.sh" 2>&1
); then
    echo 'Deployment port preflight test failed: occupied port passed.' >&2
    exit 1
fi
printf '%s\n' "${conflict_output}" | grep -Fq 'State: conflict'
printf '%s\n' "${conflict_output}" | grep -Fq 'container=other-http'
printf '%s\n' "${conflict_output}" | grep -Fq 'compose.override.yaml'

managed_output=$(
    PATH="${fixture_dir}:${PATH}" \
    FAKE_DOCKER_STATE="${temporary_root}/docker.state" \
    FAKE_DOCKER_COMPOSE_CONFIG_FILE="${temporary_root}/compose.json" \
    FAKE_DOCKER_APP_CONTAINER=geo-registry-app-test \
    FAKE_DOCKER_PORT_BINDINGS='127.0.0.1:18080' \
    FAKE_SS_LISTENERS='LISTEN 0 4096 127.0.0.1:18080 0.0.0.0:*' \
    COMPOSE_ENV_FILE="${temporary_root}/prod.secrets.env" \
    RELEASE_ENV_FILE="${temporary_root}/release.env" \
    PRODUCTION_COMPOSE_MODEL="${temporary_root}/compose-model.yaml" \
        "${project_root}/docker/release/deployment-port-preflight.sh"
)
printf '%s\n' "${managed_output}" | grep -Fq 'State: occupied-by-managed-app'

smoke_output=$(
    PATH="${fixture_dir}:${PATH}" \
    FAKE_DOCKER_STATE="${temporary_root}/docker.state" \
    FAKE_DOCKER_COMPOSE_CONFIG_FILE="${temporary_root}/compose.json" \
    COMPOSE_ENV_FILE="${temporary_root}/prod.secrets.env" \
    RELEASE_ENV_FILE="${temporary_root}/release.env" \
    PRODUCTION_COMPOSE_MODEL="${temporary_root}/compose-model.yaml" \
    DEPLOY_SMOKE_DRY_RUN=1 \
        "${project_root}/docker/release/deployment-smoke.sh"
)
printf '%s\n' "${smoke_output}" | grep -Fq 'Deployment HTTP smoke target: http://127.0.0.1:18080'

echo 'Deployment HTTP port preflight contract test passed.'
