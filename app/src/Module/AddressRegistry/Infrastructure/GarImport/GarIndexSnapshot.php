<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

final readonly class GarIndexSnapshot
{
    public function __construct(
        public string $indexName,
        public string $tableName,
    ) {}
}
