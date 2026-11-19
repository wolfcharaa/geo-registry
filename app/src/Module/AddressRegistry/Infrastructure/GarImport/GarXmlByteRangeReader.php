<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use Generator;

final readonly class GarXmlByteRangeReader
{
    private const DEFAULT_READ_BYTES = 8 * 1024 * 1024;
    private const DEFAULT_RANGE_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private GarXmlStructureInspector $inspector,
        private int $readBytes = self::DEFAULT_READ_BYTES,
        private int $maxRangeBytes = self::DEFAULT_RANGE_BYTES,
    ) {
        if ($this->readBytes < 2 || $this->maxRangeBytes < 1) {
            throw new GarImportFailed('GAR XML range reader limits must be positive.');
        }
    }

    /**
     * @param list<GarImportCommittedRange> $committedRanges
     * @return Generator<int, GarXmlByteRange>
     */
    public function ranges(
        GarXmlSourceFile $file,
        int $batchSize,
        int $processedRows = 0,
        array $committedRanges = [],
        ?int $maxRangeBytes = null,
    ): Generator {
        $rangeBytes = $maxRangeBytes ?? $this->maxRangeBytes;
        if ($batchSize < 1 || $processedRows < 0 || $rangeBytes < 1) {
            throw new GarImportFailed('GAR XML range reader received invalid row limits.');
        }

        $structure = $this->inspector->inspect($file);
        $needle = '<' . $structure->rowElement;
        $needleBytes = \strlen($needle);
        $handle = \fopen($file->path, 'rb');
        if ($handle === false) {
            throw new GarImportFailed("Unable to open GAR XML file: {$file->relativePath}");
        }

        $rowNumber = 0;
        $rangeFirstByte = null;
        $rangeFirstRow = null;
        $rangeLastRow = null;
        $committedIndex = 0;
        $carry = '';
        $read = 0;

        try {
            while (!\feof($handle)) {
                $chunk = \fread($handle, $this->readBytes);
                if ($chunk === false) {
                    throw new GarImportFailed("Unable to read GAR XML file: {$file->relativePath}");
                }
                if ($chunk === '') {
                    continue;
                }

                $data = $carry . $chunk;
                $dataFirstByte = $read - \strlen($carry);
                $offset = 0;
                $safeLastStart = \strlen($data) - $needleBytes - 1;
                while (($position = \strpos($data, $needle, $offset)) !== false) {
                    if ($position > $safeLastStart) {
                        break;
                    }
                    $offset = $position + $needleBytes;
                    if (!\str_contains(" \t\r\n>", $data[$offset])) {
                        continue;
                    }

                    $rowFirstByte = $dataFirstByte + $position;
                    ++$rowNumber;
                    while (
                        isset($committedRanges[$committedIndex])
                        && $rowNumber > $committedRanges[$committedIndex]->lastRow
                    ) {
                        ++$committedIndex;
                    }
                    $committed = $committedRanges[$committedIndex] ?? null;
                    $wanted = $rowNumber > $processedRows && !(
                        $committed instanceof GarImportCommittedRange
                        && $rowNumber >= $committed->firstRow
                        && $rowNumber <= $committed->lastRow
                    );

                    if (!$wanted) {
                        if ($rangeFirstByte !== null && $rangeFirstRow !== null && $rangeLastRow !== null) {
                            yield $rangeFirstRow => new GarXmlByteRange(
                                $rangeFirstByte,
                                $rowFirstByte,
                                $rangeFirstRow,
                                $rangeLastRow,
                            );
                            $rangeFirstByte = $rangeFirstRow = $rangeLastRow = null;
                        }
                        continue;
                    }

                    if ($rangeFirstByte === null) {
                        $rangeFirstByte = $rowFirstByte;
                        $rangeFirstRow = $rangeLastRow = $rowNumber;
                        continue;
                    }

                    if (
                        $rangeFirstRow !== null
                        && $rangeLastRow !== null
                        && (
                            $rangeLastRow - $rangeFirstRow + 1 >= $batchSize
                            || $rowFirstByte - $rangeFirstByte >= $rangeBytes
                        )
                    ) {
                        yield $rangeFirstRow => new GarXmlByteRange(
                            $rangeFirstByte,
                            $rowFirstByte,
                            $rangeFirstRow,
                            $rangeLastRow,
                        );
                        $rangeFirstByte = $rowFirstByte;
                        $rangeFirstRow = $rowNumber;
                    }
                    $rangeLastRow = $rowNumber;
                }

                $read += \strlen($chunk);
                $carry = \substr($data, -$needleBytes);
            }

            if ($rowNumber === 0) {
                throw new GarImportFailed("GAR XML file has no range-plannable rows: {$file->relativePath}");
            }
            if ($rangeFirstByte !== null && $rangeFirstRow !== null && $rangeLastRow !== null) {
                yield $rangeFirstRow => new GarXmlByteRange(
                    $rangeFirstByte,
                    $this->closingRootByte($handle, $file, $structure),
                    $rangeFirstRow,
                    $rangeLastRow,
                );
            }
        } finally {
            \fclose($handle);
        }
    }

    /** @param resource $handle */
    private function closingRootByte($handle, GarXmlSourceFile $file, GarXmlStructure $structure): int
    {
        $tailBytes = \min($file->size, 1024 * 1024);
        if ($tailBytes < 1) {
            throw new GarImportFailed("GAR XML file is empty: {$file->relativePath}");
        }
        if (\fseek($handle, $file->size - $tailBytes) !== 0) {
            throw new GarImportFailed("Unable to seek GAR XML file: {$file->relativePath}");
        }
        $tail = \fread($handle, $tailBytes);
        if ($tail === false) {
            throw new GarImportFailed("Unable to read GAR XML tail: {$file->relativePath}");
        }
        $closing = '</' . $structure->rootElement . '>';
        $position = \strrpos($tail, $closing);
        if ($position === false) {
            throw new GarImportFailed("GAR XML closing root was not found: {$file->relativePath}");
        }

        return $file->size - $tailBytes + $position;
    }
}
