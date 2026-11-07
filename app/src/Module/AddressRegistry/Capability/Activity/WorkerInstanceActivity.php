<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\Activity;

final readonly class WorkerInstanceActivity
{
    public function __construct(
        public string $instanceId,
        public string $name,
        public string $state,
        public string $activity,
        public string $transport,
        public string $queue,
        public string $heartbeatAt,
        public string $heartbeatAge,
    ) {}
}
