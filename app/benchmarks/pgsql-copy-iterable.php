<?php

declare(strict_types=1);

use PgSql\Connection;
use PgSql\Result;

const COPY_MODES = ['array', 'generator'];

/** @return never */
function usage(string $message): never
{
    \fwrite(STDERR, $message . "\n");
    \fwrite(
        STDERR,
        "Usage: GAR_COPY_BENCHMARK_DSN='host=... dbname=... user=... password=...' "
        . "php benchmarks/pgsql-copy-iterable.php <array|generator> [rows=1000000] [payload-bytes=128]\n",
    );
    exit(2);
}

function connect(string $dsn): Connection
{
    $connection = \pg_connect($dsn);
    if (!$connection instanceof Connection) {
        throw new RuntimeException('Unable to connect to the PostgreSQL COPY benchmark database.');
    }

    return $connection;
}

/** @return Generator<int, string> */
function copyRows(int $rows, string $payload): Generator
{
    for ($id = 1; $id <= $rows; ++$id) {
        yield $id . "\t" . $payload . "\n";
    }
}

/** @return array{count: int, payload_bytes: int} */
function validate(Connection $connection): array
{
    $result = \pg_query(
        $connection,
        'SELECT count(*)::bigint AS count, coalesce(sum(octet_length(payload)), 0)::bigint AS payload_bytes '
        . 'FROM pgsql_copy_iterable_benchmark',
    );
    if (!$result instanceof Result) {
        throw new RuntimeException('Unable to validate PostgreSQL COPY benchmark rows.');
    }

    $row = \pg_fetch_assoc($result);
    if (!\is_array($row) || !isset($row['count'], $row['payload_bytes'])) {
        throw new RuntimeException('PostgreSQL returned an invalid COPY benchmark result.');
    }

    return [
        'count' => (int) $row['count'],
        'payload_bytes' => (int) $row['payload_bytes'],
    ];
}

$mode = $argv[1] ?? '';
$rows = \filter_var($argv[2] ?? '1000000', FILTER_VALIDATE_INT);
$payloadBytes = \filter_var($argv[3] ?? '128', FILTER_VALIDATE_INT);
$dsn = \getenv('GAR_COPY_BENCHMARK_DSN');

if (!\in_array($mode, COPY_MODES, true)) {
    usage('Unknown COPY input mode.');
}
if ($mode === 'generator' && PHP_VERSION_ID < 80500) {
    usage('The generator mode requires PHP 8.5 or newer.');
}
if (!\is_int($rows) || $rows < 1 || !\is_int($payloadBytes) || $payloadBytes < 1) {
    usage('rows and payload-bytes must be positive integers.');
}
if (!\is_string($dsn) || \trim($dsn) === '') {
    usage('GAR_COPY_BENCHMARK_DSN is required.');
}

$connection = connect($dsn);
try {
    if (!\pg_query($connection, 'CREATE TEMP TABLE pgsql_copy_iterable_benchmark ('
        . 'id bigint NOT NULL, payload text NOT NULL)')) {
        throw new RuntimeException('Unable to create the PostgreSQL COPY benchmark table.');
    }

    $payload = \str_repeat('x', $payloadBytes);
    $prepareStartedAt = \hrtime(true);
    $input = $mode === 'array'
        ? \iterator_to_array(copyRows($rows, $payload), false)
        : copyRows($rows, $payload);
    $prepareSeconds = (\hrtime(true) - $prepareStartedAt) / 1_000_000_000;
    $memoryAfterPrepare = \memory_get_usage(true);
    \memory_reset_peak_usage();

    $copyStartedAt = \hrtime(true);
    if (!\pg_copy_from($connection, 'pgsql_copy_iterable_benchmark', $input)) {
        throw new RuntimeException('PostgreSQL COPY benchmark failed.');
    }
    $copySeconds = (\hrtime(true) - $copyStartedAt) / 1_000_000_000;
    $copyPeakMemory = \memory_get_peak_usage(true);
    $actual = validate($connection);

    if ($actual['count'] !== $rows || $actual['payload_bytes'] !== $rows * $payloadBytes) {
        throw new RuntimeException('PostgreSQL COPY benchmark validation does not match the requested payload.');
    }

    \fwrite(STDOUT, \json_encode([
        'runtime' => PHP_VERSION,
        'libpq' => \pg_version($connection)['client'] ?? 'unknown',
        'mode' => $mode,
        'rows' => $rows,
        'payload_bytes_per_row' => $payloadBytes,
        'payload_bytes' => $actual['payload_bytes'],
        'prepare_seconds' => $prepareSeconds,
        'copy_seconds' => $copySeconds,
        'total_seconds' => $prepareSeconds + $copySeconds,
        'memory_after_prepare_bytes' => $memoryAfterPrepare,
        'copy_peak_memory_bytes' => $copyPeakMemory,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
} finally {
    \pg_close($connection);
}
