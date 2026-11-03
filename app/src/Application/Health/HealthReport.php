<?php

declare(strict_types=1);

namespace App\Application\Health;

final readonly class HealthReport
{
    /** @param list<HealthComponentResult> $components */
    public function __construct(
        public HealthStatus $status,
        public array $components = [],
    ) {}

    /** @return array{status: string, components?: list<array{name: string, policy: string, status: string}>} */
    public function toArray(): array
    {
        $payload = ['status' => $this->status->value];
        if ($this->components === []) {
            return $payload;
        }

        return [
            'status' => $this->status->value,
            'components' => array_map(
                static fn(HealthComponentResult $component): array => $component->toArray(),
                $this->components,
            ),
        ];
    }
}
