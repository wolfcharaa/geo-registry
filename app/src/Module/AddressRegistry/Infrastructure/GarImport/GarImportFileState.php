<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

final readonly class GarImportFileState
{
    public function __construct(
        public int $processedRows,
        public bool $completed,
    ) {}
}
