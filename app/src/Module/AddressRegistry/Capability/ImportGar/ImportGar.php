<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\ImportGar;

use InvalidArgumentException;

final readonly class ImportGar
{
    /**
     * @param list<int> $regions
     */
    public function __construct(
        public string $sourcePath,
        public int $batchSize = 50_000,
        public int $workers = 2,
        public bool $restart = false,
        public array $regions = [],
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
        $uniqueRegions = [];
        foreach ($this->regions as $region) {
            if ($region < 1 || $region > 99 || isset($uniqueRegions[$region])) {
                throw new InvalidArgumentException('GAR import regions must contain unique codes from 1 to 99.');
            }
            $uniqueRegions[$region] = true;
        }
    }
}
