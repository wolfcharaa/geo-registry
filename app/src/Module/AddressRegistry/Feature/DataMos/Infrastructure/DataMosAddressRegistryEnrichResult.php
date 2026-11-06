<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\DataMos\Infrastructure;

final readonly class DataMosAddressRegistryEnrichResult
{
    public function __construct(
        public int $updatedSearchRows,
        public int $insertedSearchRows,
        public float $duration,
    ) {}

    public function totalRows(): int
    {
        return $this->updatedSearchRows + $this->insertedSearchRows;
    }
}
