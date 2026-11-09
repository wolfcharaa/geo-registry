#!/bin/sh
set -eu

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
project_root=$(CDPATH='' cd -- "${script_dir}/../.." && pwd)

# shellcheck disable=SC1091
. "${script_dir}/release-lib.sh"

for command_name in awk find flock git; do
    release_require_command "${command_name}"
done

ttl_days=${RELEASE_CANDIDATE_TTL_DAYS:-7}
release_validate_size "${ttl_days}"
artifact_root=$(release_resolve_path "${RELEASE_ARTIFACT_ROOT:-docker/data/release}")
release_env_file=$(release_resolve_path "${RELEASE_ENV_FILE:-docker/compose/prod/release.env}")
current_release=
if [ -f "${release_env_file}" ]; then
    current_release=$(release_env_value RELEASE_VERSION "${release_env_file}" || true)
fi

echo "Release candidate preview (read-only)"
echo "Artifact root: ${artifact_root}"
echo "TTL days: ${ttl_days}"
printf '%s\t%s\t%s\t%s\t%s\n' release state protected expired lock

found=0
if [ -d "${artifact_root}" ]; then
    for candidate_dir in "${artifact_root}"/*; do
        [ -d "${candidate_dir}" ] || continue
        found=1
        release_version=${candidate_dir##*/}
        phase_manifest="${candidate_dir}/phase-manifest.txt"
        source_manifest="${candidate_dir}/source-manifest.txt"
        state=incomplete
        if [ -f "${phase_manifest}" ]; then
            phase=$(release_manifest_value phase "${phase_manifest}" || true)
            case "${phase}" in
                prepared|built|frozen|registry-published|offline-payload|offline-exported)
                    state=${phase}
                    ;;
            esac
        fi
        if [ "${state}" = incomplete ] && [ -f "${source_manifest}" ]; then
            state=offline-payload
        fi

        protected=no
        if [ "${release_version}" = "${current_release}" ] \
            || git -C "${project_root}" show-ref --verify --quiet "refs/tags/${release_version}"; then
            protected=yes
        fi

        expired=no
        if [ -n "$(find "${candidate_dir}" -maxdepth 0 -mtime "+${ttl_days}" -print -quit)" ]; then
            expired=yes
        fi

        lock_state=none
        for lock_file in "${candidate_dir}"/.release-*.lock; do
            [ -f "${lock_file}" ] || continue
            lock_state=idle
            if ! flock -n "${lock_file}" true; then
                lock_state=active
                protected=yes
            fi
            break
        done

        printf '%s\t%s\t%s\t%s\t%s\n' \
            "${release_version}" "${state}" "${protected}" "${expired}" "${lock_state}"
    done
fi

if [ "${found}" -eq 0 ]; then
    echo "No release candidates found."
fi

echo "No files were removed. Cleanup is a separate explicitly authorized operation."
