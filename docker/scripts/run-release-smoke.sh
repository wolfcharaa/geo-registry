#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)

collection=${POSTMAN_COLLECTION:-${project_root}/docs/api/postman/geo-registry.postman_collection.json}
case "${collection}" in
    /*) ;;
    *) collection="${project_root}/${collection}" ;;
esac

if [ ! -f "${collection}" ]; then
    echo "Postman collection not found: ${collection}" >&2
    exit 2
fi

environment=${POSTMAN_ENVIRONMENT:-}
if [ -z "${environment}" ]; then
    echo "POSTMAN_ENVIRONMENT must point to a representative Postman environment." >&2
    exit 2
fi

case "${environment}" in
    /*) ;;
    *) environment="${project_root}/${environment}" ;;
esac

if [ ! -f "${environment}" ]; then
    echo "Postman environment not found: ${environment}" >&2
    exit 2
fi

artifact_dir=${RELEASE_ARTIFACT_DIR:-/tmp/geo-registry-release-smoke}
mkdir -p "${artifact_dir}"
artifact_dir=$(CDPATH='' cd -- "${artifact_dir}" && pwd)

run_name=${RELEASE_RUN_NAME:-current}
case "${run_name}" in
    ''|*[!A-Za-z0-9._-]*)
        echo "RELEASE_RUN_NAME may contain only letters, digits, dot, underscore and dash." >&2
        exit 2
        ;;
esac

base_url=${POSTMAN_BASE_URL:-http://host.docker.internal:8080}
newman_image=${NEWMAN_IMAGE:-postman/newman:6.1.3-alpine@sha256:d9b5e780ead0026bbb37f3759cd7b10fc8a88cd4030c8266a7c5e343d75a5198}

docker run --rm \
    --add-host host.docker.internal:host-gateway \
    --user "$(id -u):$(id -g)" \
    --volume "${collection}:/etc/newman/collection.json:ro" \
    --volume "${environment}:/etc/newman/environment.json:ro" \
    --volume "${artifact_dir}:/newman:rw" \
    "${newman_image}" run /etc/newman/collection.json \
    --environment /etc/newman/environment.json \
    --env-var "baseUrl=${base_url}" \
    --env-var releaseStrict=true \
    --reporters cli,junit,json \
    --reporter-junit-export "/newman/${run_name}.xml" \
    --reporter-json-export "/newman/${run_name}.json" \
    --color off \
    --timeout 600000 \
    --timeout-request 15000 \
    --timeout-script 5000

echo "Release smoke passed: ${base_url}"
echo "Artifacts: ${artifact_dir}/${run_name}.xml ${artifact_dir}/${run_name}.json"
