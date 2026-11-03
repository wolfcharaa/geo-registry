<?php

declare(strict_types=1);

namespace App\Application\Health;

final readonly class HealthComponentResult
{
    public function __construct(
        public string $name,
        public HealthCheckPolicy $policy,
        public bool $available,
    ) {}

    /** @return array{name: string, policy: string, status: string} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'policy' => $this->policy->value,
            'status' => $this->available ? HealthStatus::Ok->value : HealthStatus::Unavailable->value,
        ];
    }
}
