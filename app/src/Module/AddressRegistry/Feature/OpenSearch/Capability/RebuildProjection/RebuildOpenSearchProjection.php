<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\OpenSearch\Capability\RebuildProjection;

use InvalidArgumentException;

final readonly class RebuildOpenSearchProjection
{
    public function __construct(
        public int $batchSize = 1000,
        public int $concurrency = 4,
        public bool $switchAlias = true,
    ) {
        if ($this->batchSize < 1 || $this->batchSize > 10000) {
            throw new InvalidArgumentException('OpenSearch batch size must be between 1 and 10000.');
        }
        if ($this->concurrency < 1 || $this->concurrency > 32) {
            throw new InvalidArgumentException('OpenSearch concurrency must be between 1 and 32.');
        }
    }
}
