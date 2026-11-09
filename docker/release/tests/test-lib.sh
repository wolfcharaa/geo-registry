#!/bin/sh

contract_test_fail() {
    echo "Contract test failed: $*" >&2
    exit 1
}

contract_assert_file_contains() (
    contract_expected=$1
    contract_file=$2
    contract_context=$3

    grep -Fq -- "${contract_expected}" "${contract_file}" \
        || contract_test_fail "${contract_context}: expected '${contract_expected}' in ${contract_file}"
)

contract_assert_file_line_count() (
    contract_expected=$1
    contract_file=$2
    contract_context=$3
    contract_actual=$(wc -l <"${contract_file}" | tr -d ' ')

    [ "${contract_actual}" = "${contract_expected}" ] \
        || contract_test_fail "${contract_context}: expected ${contract_expected} lines, got ${contract_actual}"
)

contract_assert_file_empty() (
    contract_file=$1
    contract_context=$2

    [ ! -s "${contract_file}" ] \
        || contract_test_fail "${contract_context}: expected an empty file: ${contract_file}"
)

contract_assert_docker_log_read_only() (
    contract_log_file=$1
    contract_context=$2

    while IFS= read -r contract_log_line; do
        case "${contract_log_line}" in
            buildx\ build\ --help)
                ;;
            exec\ *)
                case "${contract_log_line}" in
                    *' pg_restore --list'*|*' psql '*'--command SELECT count(*) FROM pg_database WHERE datname = '*';')
                        ;;
                    *)
                        contract_test_fail "${contract_context}: Docker exec was not a recognized read-only query: ${contract_log_line}"
                        ;;
                esac
                ;;
            build\ *|create\ *|load\ *|pull\ *|push\ *|restart\ *|rm\ *|run\ *|save\ *|start\ *|stop\ *|tag\ *)
                contract_test_fail "${contract_context}: Docker mutation was attempted: ${contract_log_line}"
                ;;
            buildx\ build\ *)
                contract_test_fail "${contract_context}: Docker build was attempted: ${contract_log_line}"
                ;;
            compose\ *)
                case " ${contract_log_line} " in
                    *' build '*|*' create '*|*' down '*|*' exec '*|*' kill '*|*' pause '*|*' pull '*|*' push '*|*' restart '*|*' rm '*|*' run '*|*' start '*|*' stop '*|*' unpause '*|*' up '*)
                        contract_test_fail "${contract_context}: Compose mutation was attempted: ${contract_log_line}"
                        ;;
                esac
                ;;
        esac
    done <"${contract_log_file}"
)
