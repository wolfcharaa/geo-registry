<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

final readonly class GarIndexBuildTaskResult
{
    public function __construct(
        public string $indexName,
        public int $backendPid,
    ) {}
}
