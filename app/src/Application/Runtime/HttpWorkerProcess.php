<?php

declare(strict_types=1);

namespace App\Application\Runtime;

use InvalidArgumentException;

final readonly class HttpWorkerProcess
{
    public function __construct(
        public int $workerId,
        public int $pid,
    ) {
        if ($this->workerId < 1) {
            throw new InvalidArgumentException('HTTP worker ID must be positive.');
        }
        if ($this->pid < 1) {
            throw new InvalidArgumentException('HTTP worker PID must be positive.');
        }
    }
}
