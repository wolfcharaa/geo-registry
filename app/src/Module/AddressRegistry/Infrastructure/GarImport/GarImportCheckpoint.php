<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;

final class GarImportCheckpoint
{
    /**
     * @param list<GarImportCommittedRange> $ranges
     */
    public static function fold(int $checkpoint, array $ranges): int
    {
        if ($checkpoint < 0) {
            throw new GarImportFailed('GAR pipeline checkpoint must not be negative.');
        }

        foreach ($ranges as $range) {
            if ($range->firstRow !== $checkpoint + 1) {
                break;
            }
            $checkpoint = $range->lastRow;
        }

        return $checkpoint;
    }
}
