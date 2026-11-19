<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry\Infrastructure\GarImport;

use Amp\NullCancellation;
use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarImportCommittedRange;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlByteRange;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlByteRangeReader;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlCatalog;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlRangeParser;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlSourceFile;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlStructureInspector;
use LogicException;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Test;

#[Test]
final class GarXmlByteRangeReaderTest
{
    public function testItPlansAndParsesExactRangesAcrossSmallReadChunks(): void
    {
        $file = $this->file();
        $ranges = \array_values(\iterator_to_array(
            new GarXmlByteRangeReader(new GarXmlStructureInspector(), readBytes: 7, maxRangeBytes: 1024)
                ->ranges($file, batchSize: 2),
        ));

        if (\count($ranges) !== 2) {
            throw new LogicException('Expected two planned GAR XML ranges.');
        }
        Assert::same([$ranges[0]->firstRow, $ranges[0]->lastRow], [1, 2]);
        Assert::same([$ranges[1]->firstRow, $ranges[1]->lastRow], [3, 3]);
        Assert::same($ranges[0]->lastByte, $ranges[1]->firstByte);

        $first = new GarXmlRangeParser()->parse($file, $ranges[0], new NullCancellation());
        $second = new GarXmlRangeParser()->parse($file, $ranges[1], new NullCancellation());
        $streamed = \iterator_to_array(
            new GarXmlRangeParser()->encodedRows($file, $ranges[0], new NullCancellation()),
        );

        Assert::same([$first->firstRow, $first->lastRow, \count($first->rows)], [1, 2, 2]);
        Assert::same([$second->firstRow, $second->lastRow, \count($second->rows)], [3, 3, 1]);
        Assert::same(\array_keys($streamed), [1, 2]);
        Assert::same(\array_values($streamed), $first->rows);
        Assert::true(\str_starts_with($first->rows[0], "770011\t770001\t"));
        Assert::true(\str_starts_with($second->rows[0], "770013\t770001\t"));
    }

    public function testItExcludesTheContiguousCheckpointAndCommittedRanges(): void
    {
        $ranges = \array_values(\iterator_to_array(
            new GarXmlByteRangeReader(new GarXmlStructureInspector(), readBytes: 11, maxRangeBytes: 1024)
                ->ranges(
                    $this->file(),
                    batchSize: 3,
                    processedRows: 1,
                    committedRanges: [new GarImportCommittedRange(3, 3)],
                ),
        ));

        if (\count($ranges) !== 1) {
            throw new LogicException('Expected one uncommitted GAR XML range.');
        }
        Assert::same([$ranges[0]->firstRow, $ranges[0]->lastRow], [2, 2]);
    }

    public function testItOverridesTheRangeByteLimitForAFusedImport(): void
    {
        $ranges = \array_values(\iterator_to_array(
            new GarXmlByteRangeReader(new GarXmlStructureInspector(), readBytes: 11, maxRangeBytes: 1024)
                ->ranges($this->file(), batchSize: 3, maxRangeBytes: 1),
        ));

        Assert::same(\count($ranges), 3);
        Assert::same(
            \array_map(static fn(GarXmlByteRange $range): int => $range->rows(), $ranges),
            [1, 1, 1],
        );
    }

    #[ExpectException(GarImportFailed::class)]
    public function testItRejectsATruncatedPlannedFragmentBeforeCopy(): void
    {
        $file = $this->file();
        $ranges = \array_values(\iterator_to_array(
            new GarXmlByteRangeReader(new GarXmlStructureInspector(), readBytes: 13, maxRangeBytes: 1024)
                ->ranges($file, batchSize: 2),
        ));
        if ($ranges === []) {
            throw new LogicException('Expected a planned GAR XML range.');
        }
        $range = $ranges[0];

        new GarXmlRangeParser()->parse(
            $file,
            new GarXmlByteRange(
                $range->firstByte,
                $range->firstByte + \intdiv($range->bytes(), 2),
                $range->firstRow,
                $range->lastRow,
            ),
            new NullCancellation(),
        );
    }

    private function file(): GarXmlSourceFile
    {
        foreach (new GarXmlCatalog()->manifest(\dirname(__DIR__, 5) . '/Fixture/Gar/pipeline')->files as $file) {
            if (\str_starts_with(\basename($file->path), 'AS_HOUSES_PARAMS_')) {
                return $file;
            }
        }

        throw new LogicException('Pipeline GAR fixture is missing.');
    }
}
