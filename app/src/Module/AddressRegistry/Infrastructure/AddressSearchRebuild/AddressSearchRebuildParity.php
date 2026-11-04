<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\AddressSearchRebuild;

final readonly class AddressSearchRebuildParity
{
    public function __construct(
        public int $shadowRows,
        public int $missingRows,
        public int $duplicateRows,
        public int $invalidRegionRows,
    ) {}

    public function isValid(): bool
    {
        return $this->shadowRows > 0
            && $this->missingRows === 0
            && $this->duplicateRows === 0
            && $this->invalidRegionRows === 0;
    }
}
