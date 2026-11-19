<?php

declare(strict_types=1);

use App\Benchmark\Support\GarRangeTransportProfiler;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlByteRange;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlByteRangeReader;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlCatalog;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlStructureInspector;
use PgSql\Connection;

require \dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/Support/GarRangeTransportProfiler.php';

/** @return never */
function usage(string $message): never
{
    \fwrite(STDERR, $message . "\n");
    \fwrite(
        STDERR,
        "Usage: GAR_COPY_BENCHMARK_DSN='host=...' php benchmarks/gar-range-transport-profile.php "
            . '<xml-file> [sample-ranges=12] [range-mib=16] [batch-rows=100000] '
            . "[order=array,stream,stream,array]\n",
    );
    exit(2);
}

/**
 * @param list<GarXmlByteRange> $ranges
 * @return list<GarXmlByteRange>
 */
function spreadSample(array $ranges, int $sampleSize): array
{
    $rangeCount = \count($ranges);
    if ($sampleSize >= $rangeCount) {
        return $ranges;
    }
    if ($sampleSize === 1) {
        return [$ranges[(int) \floor(($rangeCount - 1) / 2)]];
    }

    $sample = [];
    for ($sampleIndex = 0; $sampleIndex < $sampleSize; ++$sampleIndex) {
        $rangeIndex = (int) \round($sampleIndex * ($rangeCount - 1) / ($sampleSize - 1));
        $sample[$rangeIndex] = $ranges[$rangeIndex];
    }

    return \array_values($sample);
}

/** @param list<GarXmlByteRange> $ranges */
function sampleMetrics(array $ranges): array
{
    return [
        'sample_ranges' => \count($ranges),
        'sample_rows' => \array_sum(\array_map(
            static fn(GarXmlByteRange $range): int => $range->rows(),
            $ranges,
        )),
        'sample_source_bytes' => \array_sum(\array_map(
            static fn(GarXmlByteRange $range): int => $range->bytes(),
            $ranges,
        )),
        'sample_first_rows' => \array_map(
            static fn(GarXmlByteRange $range): int => $range->firstRow,
            $ranges,
        ),
    ];
}

$path = $argv[1] ?? '';
$sampleRanges = \filter_var($argv[2] ?? '12', FILTER_VALIDATE_INT);
$rangeMib = \filter_var($argv[3] ?? '16', FILTER_VALIDATE_INT);
$batchRows = \filter_var($argv[4] ?? '100000', FILTER_VALIDATE_INT);
$order = \explode(',', $argv[5] ?? 'array,stream,stream,array');
$dsn = \getenv('GAR_COPY_BENCHMARK_DSN');

if ($path === '' || !\is_file($path)) {
    usage('The benchmark needs one existing GAR XML file.');
}
if (
    !\is_int($sampleRanges)
    || $sampleRanges < 1
    || !\is_int($rangeMib)
    || $rangeMib < 1
    || !\is_int($batchRows)
    || $batchRows < 1
) {
    usage('sample-ranges, range-mib and batch-rows must be positive integers.');
}
if ($order === [] || \array_filter($order, static fn(string $mode): bool => !\in_array($mode, ['array', 'stream'], true)) !== []) {
    usage('The crossover order may contain only array and stream modes.');
}
if (!\is_string($dsn) || \trim($dsn) === '') {
    usage('GAR_COPY_BENCHMARK_DSN is required.');
}

$file = new GarXmlCatalog()->manifest($path)->files[0];
$rangeBytes = $rangeMib * 1024 * 1024;
$rangeReader = new GarXmlByteRangeReader(
    new GarXmlStructureInspector(),
    readBytes: $rangeBytes,
    maxRangeBytes: $rangeBytes,
);
$planStartedAt = \hrtime(true);
$ranges = \iterator_to_array(
    $rangeReader->ranges($file, $batchRows, maxRangeBytes: $rangeBytes),
    false,
);
$planSeconds = (\hrtime(true) - $planStartedAt) / 1_000_000_000;
$sample = spreadSample($ranges, $sampleRanges);

$connection = \pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
if (!$connection instanceof Connection) {
    throw new RuntimeException('Unable to connect to GAR range profile PostgreSQL.');
}

try {
    $profiler = new GarRangeTransportProfiler($connection, $rangeBytes);
    $profiler->prepare($file);
    $runs = [];
    $expectedSignature = null;
    foreach ($order as $runIndex => $mode) {
        $run = ['run' => $runIndex + 1] + $profiler->profile($mode, $file, $sample);
        $signature = [$run['rows'], $run['encoded_bytes'], $run['sha256']];
        if ($expectedSignature === null) {
            $expectedSignature = $signature;
        } elseif ($signature !== $expectedSignature) {
            throw new RuntimeException('GAR range transport crossover produced different data.');
        }
        $runs[] = $run;
    }

    \fwrite(STDOUT, \json_encode([
        'runtime' => PHP_VERSION,
        'libpq' => \pg_version($connection)['client'] ?? 'unknown',
        'file' => $file->path,
        'file_bytes' => $file->size,
        'descriptor' => $file->descriptor->key,
        'table' => $file->descriptor->table,
        'batch_rows' => $batchRows,
        'range_bytes' => $rangeBytes,
        'planned_ranges' => \count($ranges),
        'planning_seconds' => $planSeconds,
        ...sampleMetrics($sample),
        'order' => $order,
        'runs' => $runs,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
} finally {
    \pg_close($connection);
}
