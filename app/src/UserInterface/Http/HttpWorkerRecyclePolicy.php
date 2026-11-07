<?php

declare(strict_types=1);

namespace App\UserInterface\Http;

use InvalidArgumentException;

final class HttpWorkerRecyclePolicy
{
    private int $handledRequests = 0;

    public function __construct(
        private readonly int $maxRequests,
        private readonly int $maxResidentMemoryBytes,
    ) {
        if ($this->maxRequests < 0) {
            throw new InvalidArgumentException('Maximum request count must be non-negative.');
        }
        if ($this->maxResidentMemoryBytes < 0) {
            throw new InvalidArgumentException('Maximum resident memory must be non-negative.');
        }
    }

    public function observe(int $residentMemoryBytes): ?HttpWorkerRecycleReason
    {
        if ($residentMemoryBytes < 0) {
            throw new InvalidArgumentException('Resident memory usage must be non-negative.');
        }

        ++$this->handledRequests;

        if ($this->maxResidentMemoryBytes > 0 && $residentMemoryBytes >= $this->maxResidentMemoryBytes) {
            return HttpWorkerRecycleReason::MaxResidentMemory;
        }
        if ($this->maxRequests > 0 && $this->handledRequests >= $this->maxRequests) {
            return HttpWorkerRecycleReason::MaxRequests;
        }

        return null;
    }

    public function handledRequests(): int
    {
        return $this->handledRequests;
    }
}
