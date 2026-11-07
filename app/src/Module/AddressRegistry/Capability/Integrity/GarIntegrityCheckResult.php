<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\Integrity;

final readonly class GarIntegrityCheckResult
{
    public function __construct(
        public string $name,
        public ?int $issues,
    ) {}

    public function isAvailable(): bool
    {
        return $this->issues !== null;
    }

    public function isValid(): bool
    {
        return $this->issues === 0;
    }
}
