#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)
compose="${project_root}/docker/compose/integration.sh"

cleanup() {
    "${compose}" down --remove-orphans
}

trap cleanup EXIT HUP INT TERM

"${compose}" --profile runner build tests
"${compose}" up --detach --wait --no-build --remove-orphans
"${compose}" --profile runner run --rm tests
