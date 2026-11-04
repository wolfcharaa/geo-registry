<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\CoordinateEnrichment\Capability;

use InvalidArgumentException;

final readonly class AddressCoordinatesUpdateResult
{
    public function __construct(
        public int $updatedRows,
        public bool $objectExists,
    ) {
        if ($this->updatedRows < 0) {
            throw new InvalidArgumentException('Updated address coordinate row count must not be negative.');
        }
        if (!$this->objectExists && $this->updatedRows !== 0) {
            throw new InvalidArgumentException('A missing address object cannot contain updated rows.');
        }
    }
}
