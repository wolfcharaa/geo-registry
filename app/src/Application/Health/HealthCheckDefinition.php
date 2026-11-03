<?php

declare(strict_types=1);

namespace App\Application\Health;

use InvalidArgumentException;

final readonly class HealthCheckDefinition
{
    public function __construct(
        public string $name,
        public HealthCheckPolicy $policy,
        public float $deadlineSeconds,
        public HealthCheckInterface $check,
    ) {
        if (trim($this->name) === '') {
            throw new InvalidArgumentException('Health check name must be a non-empty string.');
        }
        if ($this->deadlineSeconds <= 0) {
            throw new InvalidArgumentException('Health check deadline must be greater than zero.');
        }
    }
}
