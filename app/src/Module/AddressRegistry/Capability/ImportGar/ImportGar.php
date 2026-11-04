<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\ImportGar;

use InvalidArgumentException;

final readonly class ImportGar
{
    public function __construct(
        public string $sourcePath,
        public int $batchSize = 5000,
        public bool $restart = false,
    ) {
        if (trim($this->sourcePath) === '') {
            throw new InvalidArgumentException('GAR import source path must not be empty.');
        }
        if ($this->batchSize < 1 || $this->batchSize > 100_000) {
            throw new InvalidArgumentException('GAR import batch size must be between 1 and 100000.');
        }
    }
}
