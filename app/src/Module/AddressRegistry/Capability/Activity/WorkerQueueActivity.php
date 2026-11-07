<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\Activity;

final readonly class WorkerQueueActivity
{
    public function __construct(
        public string $flow,
        public string $bindingId,
        public string $status,
        public int $jobs,
        public string $oldestAt,
    ) {}
}
