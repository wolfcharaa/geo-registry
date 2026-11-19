<?php

declare(strict_types=1);

use App\Benchmark\Support\BlockingPgCopy;
use App\Benchmark\Support\NonBlockingPgCopy;
use PgSql\Connection;
use PgSql\Result;
use Revolt\EventLoop;

require \dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/Support/BlockingPgCopy.php';
require __DIR__ . '/Support/NonBlockingPgCopy.php';

const COPY_PROTOCOL_MODES = ['blocking', 'nonblocking'];

/** @return never */
function nonBlockingUsage(string $message): never
{
    \fwrite(STDERR, $message . "\n");
    \fwrite(
        STDERR,
        "Usage: GAR_COPY_BENCHMARK_DSN='host=...' php benchmarks/pgsql-copy-nonblocking.php "
        . "<blocking|nonblocking> [rows=1000000] [payload-bytes=128] [chunk-bytes=1048576]\n",
    );
    exit(2);
}

/** @return Generator<int, string> */
function copyChunks(int $rows, string $payload, int $chunkBytes): Generator
{
    $chunk = '';
    for ($id = 1; $id <= $rows; ++$id) {
        $line = $id . "\t" . $payload . "\n";
        if ($chunk !== '' && \strlen($chunk) + \strlen($line) > $chunkBytes) {
            yield $chunk;
            $chunk = '';
        }
        $chunk .= $line;
    }

    if ($chunk !== '') {
        yield $chunk;
    }
}

$mode = $argv[1] ?? '';
$rows = \filter_var($argv[2] ?? '1000000', FILTER_VALIDATE_INT);
$payloadBytes = \filter_var($argv[3] ?? '128', FILTER_VALIDATE_INT);
$chunkBytes = \filter_var($argv[4] ?? '1048576', FILTER_VALIDATE_INT);
$dsn = \getenv('GAR_COPY_BENCHMARK_DSN');

if (!\in_array($mode, COPY_PROTOCOL_MODES, true)) {
    nonBlockingUsage('Unknown COPY protocol mode.');
}
if (!\is_int($rows) || $rows < 1 || !\is_int($payloadBytes) || $payloadBytes < 1) {
    nonBlockingUsage('rows and payload-bytes must be positive integers.');
}
if (!\is_int($chunkBytes) || $chunkBytes < 1) {
    nonBlockingUsage('chunk-bytes must be a positive integer.');
}
if (!\is_string($dsn) || \trim($dsn) === '') {
    nonBlockingUsage('GAR_COPY_BENCHMARK_DSN is required.');
}

$connection = \pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
if (!$connection instanceof Connection) {
    throw new RuntimeException('Unable to connect to the PostgreSQL COPY benchmark database.');
}

try {
    if (!\pg_query($connection, 'CREATE TEMP TABLE pgsql_copy_nonblocking_benchmark ('
        . 'id bigint NOT NULL, payload text NOT NULL)')) {
        throw new RuntimeException('Unable to create the non-blocking COPY benchmark table.');
    }

    $ticks = 0;
    $ticker = EventLoop::repeat(0.001, static function () use (&$ticks): void {
        ++$ticks;
    });
    $payload = \str_repeat('x', $payloadBytes);
    \memory_reset_peak_usage();
    $startedAt = \hrtime(true);
    try {
        $copy = $mode === 'nonblocking'
            ? new NonBlockingPgCopy($connection)
            : new BlockingPgCopy($connection);
        $copyMetrics = $copy->copy(
            'COPY pgsql_copy_nonblocking_benchmark (id, payload) FROM STDIN',
            copyChunks($rows, $payload, $chunkBytes),
        );
    } finally {
        EventLoop::cancel($ticker);
    }
    $seconds = (\hrtime(true) - $startedAt) / 1_000_000_000;

    $validation = \pg_query(
        $connection,
        'SELECT count(*)::bigint AS count, coalesce(sum(octet_length(payload)), 0)::bigint AS payload_bytes '
        . 'FROM pgsql_copy_nonblocking_benchmark',
    );
    if (!$validation instanceof Result) {
        throw new RuntimeException('Unable to validate non-blocking COPY rows.');
    }
    $actual = \pg_fetch_assoc($validation);
    if (
        !\is_array($actual)
        || (int) ($actual['count'] ?? -1) !== $rows
        || (int) ($actual['payload_bytes'] ?? -1) !== $rows * $payloadBytes
    ) {
        throw new RuntimeException('Non-blocking COPY validation does not match the requested payload.');
    }

    \fwrite(STDOUT, \json_encode([
        'runtime' => PHP_VERSION,
        'libpq' => \pg_version($connection)['client'] ?? 'unknown',
        'mode' => $mode,
        'rows' => $rows,
        'payload_bytes' => $rows * $payloadBytes,
        'chunk_bytes' => $chunkBytes,
        'seconds' => $seconds,
        'event_loop_ticks' => $ticks,
        ...$copyMetrics,
        'peak_memory_bytes' => \memory_get_peak_usage(true),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
} finally {
    \pg_close($connection);
}
