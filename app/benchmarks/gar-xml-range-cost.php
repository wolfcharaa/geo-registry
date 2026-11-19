<?php

declare(strict_types=1);

use Amp\NullCancellation;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarCopyRowEncoder;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarImportCommittedRange;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlByteRange;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlByteRangeReader;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlCatalog;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlRangeParser;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlRowReader;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlSourceFile;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlStructureInspector;

require \dirname(__DIR__) . '/vendor/autoload.php';

const MODES = ['plan', 'resume-plan', 'linear', 'linear-resume', 'ranges'];

/** @return never */
function fail(string $message): never
{
    \fwrite(STDERR, $message . "\n");
    \fwrite(
        STDERR,
        "Usage: php benchmarks/gar-xml-range-cost.php <mode> <xml-file> [batch-rows=50000] [range-mib=8] [committed-percent=50]\n",
    );
    \fwrite(STDERR, 'Modes: ' . \implode(', ', MODES) . "\n");
    exit(2);
}

/** @return array{value: mixed, seconds: float} */
function measured(Closure $operation): array
{
    $startedAt = \hrtime(true);
    $value = $operation();

    return [
        'value' => $value,
        'seconds' => (\hrtime(true) - $startedAt) / 1_000_000_000,
    ];
}

/** @return list<GarXmlByteRange> */
function planRanges(GarXmlByteRangeReader $reader, GarXmlSourceFile $file, int $batchRows, int $rangeBytes): array
{
    return \iterator_to_array(
        $reader->ranges($file, $batchRows, maxRangeBytes: $rangeBytes),
        false,
    );
}

/** @param list<GarXmlByteRange> $ranges */
function rangeMetrics(array $ranges): array
{
    return [
        'ranges' => \count($ranges),
        'rows' => \array_sum(\array_map(
            static fn(GarXmlByteRange $range): int => $range->rows(),
            $ranges,
        )),
        'planned_bytes' => \array_sum(\array_map(
            static fn(GarXmlByteRange $range): int => $range->bytes(),
            $ranges,
        )),
    ];
}

/** @param list<GarXmlByteRange> $ranges */
function committedPrefix(array $ranges, int $percent): array
{
    $take = (int) \floor(\count($ranges) * ($percent / 100));
    if ($take < 1) {
        $take = 1;
    }

    return \array_map(
        static fn(GarXmlByteRange $range): GarImportCommittedRange => new GarImportCommittedRange(
            $range->firstRow,
            $range->lastRow,
        ),
        \array_slice($ranges, 0, $take),
    );
}

/** @return array{rows: int, encoded_bytes: int, sha256: string} */
function linearParse(GarXmlSourceFile $file, int $skipRows): array
{
    $reader = new GarXmlRowReader();
    $encoder = new GarCopyRowEncoder();
    $hash = \hash_init('sha256');
    $rows = 0;
    $encodedBytes = 0;

    foreach ($reader->rows($file, $skipRows) as $row) {
        $encoded = $encoder->encode($row);
        \hash_update($hash, $encoded);
        $encodedBytes += \strlen($encoded);
        ++$rows;
    }

    return [
        'rows' => $rows,
        'encoded_bytes' => $encodedBytes,
        'sha256' => \hash_final($hash),
    ];
}

/**
 * @param list<GarXmlByteRange> $ranges
 * @return array{rows: int, encoded_bytes: int, sha256: string}
 */
function rangeParse(GarXmlSourceFile $file, array $ranges, int $rangeBytes): array
{
    $parser = new GarXmlRangeParser(\max(8 * 1024 * 1024, $rangeBytes * 4));
    $cancellation = new NullCancellation();
    $hash = \hash_init('sha256');
    $rows = 0;
    $encodedBytes = 0;

    foreach ($ranges as $range) {
        $parsed = $parser->parse($file, $range, $cancellation);
        foreach ($parsed->rows as $encoded) {
            \hash_update($hash, $encoded);
        }
        $rows += \count($parsed->rows);
        $encodedBytes += $parsed->encodedBytes;
    }

    return [
        'rows' => $rows,
        'encoded_bytes' => $encodedBytes,
        'sha256' => \hash_final($hash),
    ];
}

$mode = $argv[1] ?? '';
$path = $argv[2] ?? '';
$batchRows = \filter_var($argv[3] ?? '50000', FILTER_VALIDATE_INT);
$rangeMib = \filter_var($argv[4] ?? '8', FILTER_VALIDATE_INT);
$committedPercent = \filter_var($argv[5] ?? '50', FILTER_VALIDATE_INT);

if (!\in_array($mode, MODES, true)) {
    fail('Unknown benchmark mode.');
}
if ($path === '' || !\is_file($path)) {
    fail('The benchmark needs one existing GAR XML file.');
}
if (!\is_int($batchRows) || $batchRows < 1 || !\is_int($rangeMib) || $rangeMib < 1) {
    fail('batch-rows and range-mib must be positive integers.');
}
if (!\is_int($committedPercent) || $committedPercent < 1 || $committedPercent > 99) {
    fail('committed-percent must be between 1 and 99.');
}

$manifest = new GarXmlCatalog()->manifest($path);
$file = $manifest->files[0];
$rangeBytes = $rangeMib * 1024 * 1024;
$rangeReader = new GarXmlByteRangeReader(
    new GarXmlStructureInspector(),
    readBytes: $rangeBytes,
    maxRangeBytes: $rangeBytes,
);
$result = [
    'mode' => $mode,
    'runtime' => PHP_VERSION,
    'file' => $file->path,
    'file_bytes' => $file->size,
    'batch_rows' => $batchRows,
    'range_bytes' => $rangeBytes,
];

if ($mode === 'plan') {
    $measurement = measured(static fn(): array => planRanges($rangeReader, $file, $batchRows, $rangeBytes));
    $result += rangeMetrics($measurement['value']);
    $result['seconds'] = $measurement['seconds'];
} elseif ($mode === 'resume-plan') {
    $setup = measured(static fn(): array => planRanges($rangeReader, $file, $batchRows, $rangeBytes));
    $committed = committedPrefix($setup['value'], $committedPercent);
    $measurement = measured(static fn(): array => \iterator_to_array(
        $rangeReader->ranges(
            $file,
            $batchRows,
            committedRanges: $committed,
            maxRangeBytes: $rangeBytes,
        ),
        false,
    ));
    $result += rangeMetrics($measurement['value']);
    $result['committed_percent'] = $committedPercent;
    $result['committed_ranges'] = \count($committed);
    $result['committed_rows'] = \array_sum(\array_map(
        static fn(GarImportCommittedRange $range): int => $range->lastRow - $range->firstRow + 1,
        $committed,
    ));
    $result['setup_seconds'] = $setup['seconds'];
    $result['seconds'] = $measurement['seconds'];
} elseif ($mode === 'linear') {
    $measurement = measured(static fn(): array => linearParse($file, 0));
    $result += $measurement['value'];
    $result['seconds'] = $measurement['seconds'];
} elseif ($mode === 'linear-resume') {
    $setup = measured(static fn(): array => planRanges($rangeReader, $file, $batchRows, $rangeBytes));
    $totalRows = rangeMetrics($setup['value'])['rows'];
    $skipRows = (int) \floor($totalRows * ($committedPercent / 100));
    $measurement = measured(static fn(): array => linearParse($file, $skipRows));
    $result += $measurement['value'];
    $result['total_rows'] = $totalRows;
    $result['skip_rows'] = $skipRows;
    $result['committed_percent'] = $committedPercent;
    $result['setup_seconds'] = $setup['seconds'];
    $result['seconds'] = $measurement['seconds'];
} else {
    $plan = measured(static fn(): array => planRanges($rangeReader, $file, $batchRows, $rangeBytes));
    $parse = measured(static fn(): array => rangeParse($file, $plan['value'], $rangeBytes));
    $result += rangeMetrics($plan['value']);
    $result += $parse['value'];
    $result['plan_seconds'] = $plan['seconds'];
    $result['parse_seconds'] = $parse['seconds'];
    $result['seconds'] = $plan['seconds'] + $parse['seconds'];
}

$result['peak_memory_bytes'] = \memory_get_peak_usage(true);
\fwrite(STDOUT, \json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
