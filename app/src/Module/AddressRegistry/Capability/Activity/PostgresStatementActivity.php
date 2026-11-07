<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\Activity;

final readonly class PostgresStatementActivity
{
    public function __construct(
        public int $pid,
        public string $application,
        public string $state,
        public string $age,
        public ?string $waitEventType,
        public ?string $waitEvent,
        public string $query,
    ) {}
}
