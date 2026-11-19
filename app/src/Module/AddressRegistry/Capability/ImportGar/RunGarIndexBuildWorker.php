<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\ImportGar;

use InvalidArgumentException;

final readonly class RunGarIndexBuildWorker
{
    public function __construct(
        public int $operationId,
        public string $indexName,
    ) {
        if ($this->operationId < 1) {
            throw new InvalidArgumentException('GAR index worker operation id must be positive.');
        }
        if (\preg_match('/^[a-zA-Z_][a-zA-Z0-9_$]*$/D', $this->indexName) !== 1) {
            throw new InvalidArgumentException('GAR index worker received an invalid index name.');
        }
    }
}
