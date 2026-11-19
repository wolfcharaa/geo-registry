<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;

final readonly class GarDirectRangeReady
{
    public function __construct(
        public int $firstRow,
        public int $lastRow,
        public int $encodedBytes,
    ) {
        if ($this->firstRow < 1 || $this->lastRow < $this->firstRow || $this->encodedBytes < 1) {
            throw new GarImportFailed('GAR direct range readiness metadata is invalid.');
        }
    }
}
