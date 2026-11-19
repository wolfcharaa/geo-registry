<?php

declare(strict_types=1);

use App\Benchmark\Support\BlockingTransactionalPgCopy;
use App\Benchmark\Support\GarBinaryCopyRowEncoder;
use App\Benchmark\Support\GarPostgresInsert;
use App\Benchmark\Support\GarTextCopyRowEncoder;
use App\Benchmark\Support\GarTransportChunkSource;
use App\Benchmark\Support\GarXmlRowPrefixSource;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlCatalog;
use PgSql\Connection;
use PgSql\Result;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/Support/BlockingTransactionalPgCopy.php';
require __DIR__ . '/Support/GarBenchmarkRowEncoder.php';
require __DIR__ . '/Support/GarBinaryCopyRowEncoder.php';
require __DIR__ . '/Support/GarPostgresInsert.php';
require __DIR__ . '/Support/GarTextCopyRowEncoder.php';
require __DIR__ . '/Support/GarTransportChunkSource.php';
require __DIR__ . '/Support/GarXmlRowPrefixSource.php';

const GAR_TRANSPORT_MODES = [
    'text-copy',
    'binary-copy',
    'insert-single',
    'insert-values',
];
const GAR_TRANSPORT_COPY_SQL = <<<'SQL'
COPY gar_xml_copy_benchmark (
    id, object_id, change_id, change_id_end, type_id,
    value, update_date, start_date, end_date
) FROM STDIN
SQL;

/** @return never */
function transportUsage(string $message): never
{
    fwrite(STDERR, $message . "\n");
    fwrite(
        STDERR,
        "Usage: GAR_COPY_BENCHMARK_DSN='host=...' php benchmarks/gar-xml-transport-alternatives.php "
        . '<text-copy|binary-copy|insert-single|insert-values> <object-params-xml> '
        . "[row-limit=100000] [insert-batch=1000] [chunk-bytes=1048576] [transaction-bytes=4194304]\n",
    );
    exit(2);
}

function createTransportTable(Connection $connection): void
{
    transportCommand($connection, <<<'SQL'
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
SQL, 'Unable to create GAR transport benchmark table.');
}

function createTransportLedger(Connection $connection): void
{
    transportCommand($connection, <<<'SQL'
CREATE TEMP TABLE gar_xml_copy_ledger (
    sequence integer PRIMARY KEY,
    encoded_bytes bigint NOT NULL
)
SQL, 'Unable to create GAR transport benchmark ledger.');
}

/** @return array{rows: int, logical_bytes: int, logical_sha256: string, stored_value_bytes: int} */
function validateTransport(Connection $connection): array
{
    transportCommand($connection, <<<'SQL'
CREATE TEMP TABLE gar_xml_copy_validation AS
SELECT *
FROM gar_xml_copy_benchmark
ORDER BY
    id, object_id, change_id, change_id_end, type_id,
    value, update_date, start_date, end_date
SQL, 'Unable to sort GAR transport benchmark rows for deterministic validation.');

    $lines = pg_copy_to($connection, 'gar_xml_copy_validation');
    if (!is_array($lines)) {
        throw new RuntimeException('Unable to read GAR transport benchmark rows back through COPY.');
    }

    $hash = hash_init('sha256');
    $logicalBytes = 0;
    foreach ($lines as $line) {
        hash_update($hash, $line);
        $logicalBytes += strlen($line);
    }

    $result = pg_query(
        $connection,
        'SELECT coalesce(sum(octet_length(value)), 0)::bigint AS value_bytes FROM gar_xml_copy_benchmark',
    );
    if (!$result instanceof Result) {
        throw new RuntimeException('Unable to validate GAR transport benchmark value bytes.');
    }
    $row = pg_fetch_assoc($result);
    pg_free_result($result);
    if (!is_array($row) || !isset($row['value_bytes'])) {
        throw new RuntimeException('PostgreSQL returned invalid GAR transport validation metrics.');
    }

    return [
        'rows' => count($lines),
        'logical_bytes' => $logicalBytes,
        'logical_sha256' => hash_final($hash),
        'stored_value_bytes' => (int) $row['value_bytes'],
    ];
}

function transportCommand(Connection $connection, string $sql, string $failure): void
{
    $result = pg_query($connection, $sql);
    if (!$result instanceof Result || pg_result_status($result) !== PGSQL_COMMAND_OK) {
        throw new RuntimeException($failure);
    }
    pg_free_result($result);
}

$mode = $argv[1] ?? '';
$path = $argv[2] ?? '';
$rowLimit = filter_var($argv[3] ?? '100000', FILTER_VALIDATE_INT);
$insertBatch = filter_var($argv[4] ?? '1000', FILTER_VALIDATE_INT);
$chunkBytes = filter_var($argv[5] ?? '1048576', FILTER_VALIDATE_INT);
$transactionBytes = filter_var($argv[6] ?? '4194304', FILTER_VALIDATE_INT);
$dsn = getenv('GAR_COPY_BENCHMARK_DSN');

if (!in_array($mode, GAR_TRANSPORT_MODES, true)) {
    transportUsage('Unknown GAR transport benchmark mode.');
}
if ($path === '' || !is_file($path)) {
    transportUsage('The GAR transport benchmark needs one existing object-parameters XML file.');
}
if (
    !is_int($rowLimit)
    || $rowLimit < 1
    || !is_int($insertBatch)
    || $insertBatch < 1
    || !is_int($chunkBytes)
    || $chunkBytes < 1
    || !is_int($transactionBytes)
    || $transactionBytes < $chunkBytes
) {
    transportUsage('Numeric limits must be positive and transaction-bytes must cover one transport chunk.');
}
if ($insertBatch * 9 > 65_535) {
    transportUsage('insert-batch exceeds the PostgreSQL parameter limit for nine columns.');
}
if (!is_string($dsn) || trim($dsn) === '') {
    transportUsage('GAR_COPY_BENCHMARK_DSN is required.');
}

$file = new GarXmlCatalog()->manifest($path)->files[0];
if ($file->descriptor->key !== 'object-parameters') {
    transportUsage('The current binary schema benchmark accepts only a GAR object-parameters XML file.');
}

$connection = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
if (!$connection instanceof Connection) {
    throw new RuntimeException('Unable to connect to GAR transport benchmark PostgreSQL.');
}

try {
    createTransportTable($connection);
    if ($mode === 'text-copy' || $mode === 'binary-copy') {
        createTransportLedger($connection);
    }

    $source = new GarXmlRowPrefixSource($file, $rowLimit);
    memory_reset_peak_usage();
    $startedAt = hrtime(true);

    if ($mode === 'text-copy' || $mode === 'binary-copy') {
        $binary = $mode === 'binary-copy';
        $chunks = new GarTransportChunkSource(
            $source,
            $binary ? new GarBinaryCopyRowEncoder() : new GarTextCopyRowEncoder(),
            $chunkBytes,
        );
        $copyMetrics = new BlockingTransactionalPgCopy($connection)->copy(
            GAR_TRANSPORT_COPY_SQL . ($binary ? ' (FORMAT BINARY)' : ''),
            $chunks,
            $transactionBytes,
            $binary ? GarBinaryCopyRowEncoder::header() : '',
            $binary ? GarBinaryCopyRowEncoder::trailer() : '',
        );
        $transportMetrics = $chunks->metrics();
        if ($copyMetrics['committed_bytes'] !== $transportMetrics['encoded_bytes']) {
            throw new RuntimeException('Committed GAR COPY bytes do not match the encoded transport payload.');
        }
        $operationMetrics = $copyMetrics;
    } else {
        $batchRows = $mode === 'insert-single' ? 1 : $insertBatch;
        $operationMetrics = new GarPostgresInsert($connection)->insert($source, $batchRows);
        $transportMetrics = [
            'rows' => $operationMetrics['rows'],
            'encoded_bytes' => null,
            'chunks' => null,
            'max_chunk_bytes' => null,
            'encode_seconds' => null,
            'transport_sha256' => null,
        ];
    }

    $seconds = (hrtime(true) - $startedAt) / 1_000_000_000;
    $peakMemoryBytes = memory_get_peak_usage(true);
    $sourceRows = $source->rows();
    if ($sourceRows !== $transportMetrics['rows']) {
        throw new RuntimeException('GAR transport row count does not match parsed source rows.');
    }

    $validationStartedAt = hrtime(true);
    $validation = validateTransport($connection);
    $validationSeconds = (hrtime(true) - $validationStartedAt) / 1_000_000_000;
    if ($validation['rows'] !== $sourceRows) {
        throw new RuntimeException('Stored GAR transport row count does not match parsed source rows.');
    }
    fwrite(STDOUT, json_encode([
        'runtime' => PHP_VERSION,
        'libpq' => pg_version($connection)['client'] ?? 'unknown',
        'mode' => $mode,
        'file' => $file->path,
        'file_bytes' => $file->size,
        'row_limit' => $rowLimit,
        'insert_batch' => $mode === 'insert-values' ? $insertBatch : null,
        'chunk_bytes' => $mode === 'text-copy' || $mode === 'binary-copy' ? $chunkBytes : null,
        'transaction_bytes' => $mode === 'text-copy' || $mode === 'binary-copy' ? $transactionBytes : null,
        'source_rows' => $sourceRows,
        ...$transportMetrics,
        ...$operationMetrics,
        ...$validation,
        'seconds' => $seconds,
        'validation_seconds' => $validationSeconds,
        'peak_memory_bytes' => $peakMemoryBytes,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
} finally {
    pg_close($connection);
}
