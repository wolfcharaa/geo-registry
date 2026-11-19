<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

final readonly class GarDirectCopyStreamResult
{
    public function __construct(
        public int $processedRows,
        public int $rows,
        public int $encodedBytes,
        public int $transactions,
    ) {}
}
