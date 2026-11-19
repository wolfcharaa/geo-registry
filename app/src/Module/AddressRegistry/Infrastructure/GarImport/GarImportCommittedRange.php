<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use InvalidArgumentException;

final readonly class GarImportCommittedRange
{
    public function __construct(
        public int $firstRow,
        public int $lastRow,
    ) {
        if ($this->firstRow < 1 || $this->lastRow < $this->firstRow) {
            throw new InvalidArgumentException('GAR committed row range is invalid.');
        }
    }
}
