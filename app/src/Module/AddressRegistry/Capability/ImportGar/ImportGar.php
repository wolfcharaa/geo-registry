<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\ImportGar;

use InvalidArgumentException;

final readonly class ImportGar
{
    public function __construct(
        public string $sourcePath,
        public int $batchSize = 50_000,
        public int $workers = 2,
        public bool $restart = false,
    ) {
        if (trim($this->sourcePath) === '') {
            throw new InvalidArgumentException('GAR import source path must not be empty.');
        }
        if ($this->batchSize < 1 || $this->batchSize > 100_000) {
            throw new InvalidArgumentException('GAR import batch size must be between 1 and 100000.');
        }
        if ($this->workers < 1 || $this->workers > 16) {
            throw new InvalidArgumentException('GAR import worker count must be between 1 and 16.');
        }
    }
}
