<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

final readonly class GarForeignKeySnapshot
{
    public function __construct(
        public string $constraintName,
        public string $tableName,
    ) {}
}
