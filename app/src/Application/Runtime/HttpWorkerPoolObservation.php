<?php

declare(strict_types=1);

namespace App\Application\Runtime;

final readonly class HttpWorkerPoolObservation
{
    /** @param list<int> $removedWorkerIds */
    public function __construct(
        public int $activeWorkers,
        public int $expectedWorkers,
        public array $removedWorkerIds,
        public ?HttpWorkerPoolTransition $transition,
    ) {}
}
