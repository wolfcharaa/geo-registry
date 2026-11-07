<?php

declare(strict_types=1);

namespace App\Infrastructure\Process;

final readonly class ProcessRestartBackoffDecision
{
    public function __construct(
        public float $delaySeconds,
        public int $restartsInWindow,
    ) {}
}
