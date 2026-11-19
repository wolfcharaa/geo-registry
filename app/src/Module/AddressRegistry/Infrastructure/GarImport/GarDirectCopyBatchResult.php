<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

final readonly class GarDirectCopyBatchResult
{
    public function __construct(
        public int $firstRow,
        public int $lastRow,
        public int $processedRows,
        public int $encodedBytes,
        public int $backendPid,
        public ?GarFusedRangeProfile $profile = null,
    ) {}
}
