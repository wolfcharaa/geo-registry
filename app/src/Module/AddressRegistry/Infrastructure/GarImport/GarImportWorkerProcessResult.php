<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

final readonly class GarImportWorkerProcessResult
{
    public function __construct(
        public int $exitCode,
        public string $description,
        public string $standardOutput,
        public string $standardError,
    ) {}
}
