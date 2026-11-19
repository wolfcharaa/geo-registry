<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\ImportGar;

use InvalidArgumentException;

final readonly class RunGarIndexScheduler
{
    public function __construct(
        public int $operationId,
        public int $workerCount,
        public int $startPercent = 0,
        public bool $persistentWorkers = false,
    ) {
        if ($this->operationId < 1) {
            throw new InvalidArgumentException('GAR index scheduler operation id must be positive.');
        }
        if ($this->workerCount < 1 || $this->workerCount > 4) {
            throw new InvalidArgumentException('GAR index scheduler worker count must be between 1 and 4.');
        }
        if ($this->startPercent < 0 || $this->startPercent > 99) {
            throw new InvalidArgumentException('GAR index scheduler start percent must be between 0 and 99.');
        }
    }
}
