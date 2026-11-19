<?php

declare(strict_types=1);

use App\Benchmark\Support\BlockingPgCopy;
use App\Benchmark\Support\BlockingTransactionalPgCopy;
use App\Benchmark\Support\GarXmlCopyChunkSource;
use App\Benchmark\Support\NonBlockingPgCopy;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarCopyRowEncoder;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlCatalog;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlRowReader;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlSourceFile;
use PgSql\Connection;
use PgSql\Result;
use Revolt\EventLoop;

require \dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/Support/BlockingPgCopy.php';
require __DIR__ . '/Support/BlockingTransactionalPgCopy.php';
require __DIR__ . '/Support/GarXmlCopyChunkSource.php';
require __DIR__ . '/Support/NonBlockingPgCopy.php';

const GAR_XML_COPY_MODES = [
    'pg-copy-batches',
    'blocking-bounded',
    'blocking-chunks',
    'blocking-transactions',
    'nonblocking-chunks',
];
const GAR_XML_COPY_SQL = <<<'SQL'
COPY gar_xml_copy_benchmark (
    id, object_id, change_id, change_id_end, type_id,
    value, update_date, start_date, end_date
) FROM STDIN
SQL;

/** @return never */
function copyPipelineUsage(string $message): never
{
    \fwrite(STDERR, $message . "\n");
    \fwrite(
        STDERR,
        "Usage: GAR_COPY_BENCHMARK_DSN='host=...' php benchmarks/gar-xml-copy-pipeline.php "
        . "<pg-copy-batches|blocking-bounded|blocking-chunks|blocking-transactions|nonblocking-chunks> "
        . "<xml-file> [batch-rows=50000] [chunk-bytes=1048576] [transaction-bytes=0]\n",
    );
    exit(2);
}

function createCopyLedger(Connection $connection): void
{
    $result = \pg_query($connection, <<<'SQL'
CREATE TEMP TABLE gar_xml_copy_ledger (
    sequence integer PRIMARY KEY,
    encoded_bytes bigint NOT NULL
)
SQL);
    if (!$result instanceof Result || \pg_result_status($result) !== PGSQL_COMMAND_OK) {
        throw new RuntimeException('Unable to create GAR XML COPY benchmark ledger.');
    }
    \pg_free_result($result);
}

function createCopyTable(Connection $connection): void
{
    $result = \pg_query($connection, <<<'SQL'
CREATE TEMP TABLE gar_xml_copy_benchmark (
    id bigint NOT NULL,
    object_id bigint,
    change_id bigint,
    change_id_end bigint,
    type_id integer,
    value text,
    update_date date,
    start_date date,
    end_date date
)
SQL);
    if (!$result instanceof Result || \pg_result_status($result) !== PGSQL_COMMAND_OK) {
        throw new RuntimeException('Unable to create GAR XML COPY benchmark table.');
    }
    \pg_free_result($result);
}

/**
 * @return array{rows: int, encoded_bytes: int, chunks: int, encode_seconds: float, copy_seconds: float, sha256: string}
 */
function copyProductionBatches(
    Connection $connection,
    GarXmlSourceFile $file,
    int $batchRows,
): array {
    $reader = new GarXmlRowReader();
    $encoder = new GarCopyRowEncoder();
    $hash = \hash_init('sha256');
    $batch = [];
    $rows = 0;
    $encodedBytes = 0;
    $batches = 0;
    $encodeNanoseconds = 0;
    $copyNanoseconds = 0;

    foreach ($reader->rows($file, 0) as $values) {
        $startedAt = \hrtime(true);
        $encoded = $encoder->encode($values);
        \hash_update($hash, $encoded);
        $encodedBytes += \strlen($encoded);
        $batch[] = $encoded;
        ++$rows;
        $encodeNanoseconds += \hrtime(true) - $startedAt;

        if (\count($batch) < $batchRows) {
            continue;
        }
        $startedAt = \hrtime(true);
        if (!\pg_copy_from($connection, 'gar_xml_copy_benchmark', $batch)) {
            throw new RuntimeException('Production-style pg_copy_from() batch failed.');
        }
        $copyNanoseconds += \hrtime(true) - $startedAt;
        ++$batches;
        $batch = [];
    }
    if ($batch !== []) {
        $startedAt = \hrtime(true);
        if (!\pg_copy_from($connection, 'gar_xml_copy_benchmark', $batch)) {
            throw new RuntimeException('Final production-style pg_copy_from() batch failed.');
        }
        $copyNanoseconds += \hrtime(true) - $startedAt;
        ++$batches;
    }

    return [
        'rows' => $rows,
        'encoded_bytes' => $encodedBytes,
        'chunks' => $batches,
        'encode_seconds' => $encodeNanoseconds / 1_000_000_000,
        'copy_seconds' => $copyNanoseconds / 1_000_000_000,
        'sha256' => \hash_final($hash),
    ];
}

/** @return array{rows: int, stored_value_bytes: int} */
function validateCopy(Connection $connection): array
{
    $result = \pg_query(
        $connection,
        'SELECT count(*)::bigint AS rows, coalesce(sum(octet_length(value)), 0)::bigint AS value_bytes '
        . 'FROM gar_xml_copy_benchmark',
    );
    if (!$result instanceof Result) {
        throw new RuntimeException('Unable to validate GAR XML COPY benchmark table.');
    }
    $row = \pg_fetch_assoc($result);
    if (!\is_array($row) || !isset($row['rows'], $row['value_bytes'])) {
        throw new RuntimeException('PostgreSQL returned invalid GAR XML COPY validation metrics.');
    }

    return [
        'rows' => (int) $row['rows'],
        'stored_value_bytes' => (int) $row['value_bytes'],
    ];
}

$mode = $argv[1] ?? '';
$path = $argv[2] ?? '';
$batchRows = \filter_var($argv[3] ?? '50000', FILTER_VALIDATE_INT);
$chunkBytes = \filter_var($argv[4] ?? '1048576', FILTER_VALIDATE_INT);
$transactionBytes = \filter_var($argv[5] ?? '0', FILTER_VALIDATE_INT);
$dsn = \getenv('GAR_COPY_BENCHMARK_DSN');
if (!\in_array($mode, GAR_XML_COPY_MODES, true)) {
    copyPipelineUsage('Unknown GAR XML COPY benchmark mode.');
}
if ($path === '' || !\is_file($path)) {
    copyPipelineUsage('The benchmark needs one existing GAR XML file.');
}
if (
    !\is_int($batchRows)
    || $batchRows < 1
    || !\is_int($chunkBytes)
    || $chunkBytes < 1
    || !\is_int($transactionBytes)
    || $transactionBytes < 0
) {
    copyPipelineUsage('batch-rows and chunk-bytes must be positive; transaction-bytes must not be negative.');
}
if (!\is_string($dsn) || \trim($dsn) === '') {
    copyPipelineUsage('GAR_COPY_BENCHMARK_DSN is required.');
}
if ($mode === 'blocking-transactions' && $transactionBytes > 0 && $transactionBytes < $chunkBytes) {
    copyPipelineUsage('transaction-bytes must be zero or at least as large as chunk-bytes.');
}

$file = new GarXmlCatalog()->manifest($path)->files[0];
$connection = \pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
if (!$connection instanceof Connection) {
    throw new RuntimeException('Unable to connect to GAR XML COPY benchmark PostgreSQL.');
}

try {
    createCopyTable($connection);
    if ($mode === 'blocking-transactions') {
        createCopyLedger($connection);
    }
    $ticks = 0;
    $ticker = EventLoop::repeat(0.001, static function () use (&$ticks): void {
        ++$ticks;
    });
    \memory_reset_peak_usage();
    $startedAt = \hrtime(true);
    try {
        if ($mode === 'pg-copy-batches') {
            $sourceMetrics = copyProductionBatches($connection, $file, $batchRows);
            $copyMetrics = [
                'readable_waits' => 0,
                'writable_waits' => 0,
                'copy_backpressure' => 0,
            ];
        } elseif ($mode === 'blocking-bounded') {
            $source = new GarXmlCopyChunkSource($file, $chunkBytes);
            $copy = new BlockingPgCopy($connection);
            $copyNanoseconds = 0.0;
            foreach ($source as $chunk) {
                $copyStartedAt = \hrtime(true);
                $copy->copy(GAR_XML_COPY_SQL, [$chunk]);
                $copyNanoseconds += \hrtime(true) - $copyStartedAt;
            }
            $sourceMetrics = $source->metrics() + [
                'copy_seconds' => $copyNanoseconds / 1_000_000_000,
            ];
            $copyMetrics = [
                'readable_waits' => 0,
                'writable_waits' => 0,
                'copy_backpressure' => 0,
            ];
        } elseif ($mode === 'blocking-transactions') {
            $source = new GarXmlCopyChunkSource($file, $chunkBytes);
            $copy = new BlockingTransactionalPgCopy($connection);
            $copyMetrics = $copy->copy(
                GAR_XML_COPY_SQL,
                $source,
                $transactionBytes === 0 ? PHP_INT_MAX : $transactionBytes,
            );
            $sourceMetrics = $source->metrics();
            if ($copyMetrics['committed_bytes'] !== $sourceMetrics['encoded_bytes']) {
                throw new RuntimeException('Committed COPY ledger bytes do not match encoded source bytes.');
            }
        } else {
            $source = new GarXmlCopyChunkSource($file, $chunkBytes);
            $copy = $mode === 'nonblocking-chunks'
                ? new NonBlockingPgCopy($connection)
                : new BlockingPgCopy($connection);
            $copyMetrics = $copy->copy(GAR_XML_COPY_SQL, $source);
            $sourceMetrics = $source->metrics() + ['copy_seconds' => null];
        }
    } finally {
        EventLoop::cancel($ticker);
    }
    $seconds = (\hrtime(true) - $startedAt) / 1_000_000_000;
    $validationStartedAt = \hrtime(true);
    $validation = validateCopy($connection);
    $validationSeconds = (\hrtime(true) - $validationStartedAt) / 1_000_000_000;
    if ($validation['rows'] !== $sourceMetrics['rows']) {
        throw new RuntimeException('GAR XML COPY row count does not match parsed source rows.');
    }

    \fwrite(STDOUT, \json_encode([
        'runtime' => PHP_VERSION,
        'libpq' => \pg_version($connection)['client'] ?? 'unknown',
        'mode' => $mode,
        'file' => $file->path,
        'file_bytes' => $file->size,
        'batch_rows' => $batchRows,
        'chunk_bytes' => $chunkBytes,
        'transaction_bytes' => $mode === 'blocking-transactions' ? $transactionBytes : null,
        ...$sourceMetrics,
        ...$copyMetrics,
        ...$validation,
        'seconds' => $seconds,
        'validation_seconds' => $validationSeconds,
        'event_loop_ticks' => $ticks,
        'peak_memory_bytes' => \memory_get_peak_usage(true),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
} finally {
    \pg_close($connection);
}
