<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\DataMos\Infrastructure;

final readonly class DataMosAddressRegistryImportResult
{
    public function __construct(
        public string $source,
        public int $readRows,
        public int $writtenRows,
        public int $skippedRows,
        public float $duration,
        public bool $dryRun,
    ) {}

    public function rowsPerSecond(): float
    {
        if ($this->duration <= 0.0) {
            return 0.0;
        }

        return $this->readRows / $this->duration;
    }
}
