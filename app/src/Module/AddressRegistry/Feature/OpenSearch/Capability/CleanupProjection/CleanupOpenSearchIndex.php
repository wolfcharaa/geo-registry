<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\OpenSearch\Capability\CleanupProjection;

use InvalidArgumentException;

final readonly class CleanupOpenSearchIndex
{
    public function __construct(
        public string $index,
        public string $confirmation,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $this->index) !== 1) {
            throw new InvalidArgumentException('OpenSearch cleanup index has an invalid name.');
        }
        if ($this->confirmation !== $this->index) {
            throw new InvalidArgumentException('OpenSearch cleanup confirmation must exactly match the index name.');
        }
    }
}
