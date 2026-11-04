<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\RebuildAddressSearch;

use InvalidArgumentException;

final readonly class RebuildAddressSearch
{
    public function __construct(
        public int $batchSize = 50_000,
        public bool $restart = false,
        public bool $buildOnly = false,
    ) {
        if ($this->batchSize < 1 || $this->batchSize > 1_000_000) {
            throw new InvalidArgumentException('Address search rebuild batch size must be between 1 and 1000000.');
        }
    }
}
