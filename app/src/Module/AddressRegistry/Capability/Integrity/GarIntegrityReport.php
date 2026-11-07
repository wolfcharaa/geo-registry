<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\Integrity;

final readonly class GarIntegrityReport
{
    /**
     * @param non-empty-list<int> $regions
     * @param list<GarIntegrityCheckResult> $checks
     */
    public function __construct(
        public array $regions,
        public bool $deep,
        public array $checks,
    ) {}

    public function isValid(): bool
    {
        foreach ($this->checks as $check) {
            if (!$check->isValid()) {
                return false;
            }
        }

        return true;
    }

    public function failedChecks(): int
    {
        return count(array_filter(
            $this->checks,
            static fn(GarIntegrityCheckResult $check): bool => $check->issues !== null && $check->issues > 0,
        ));
    }

    public function unavailableChecks(): int
    {
        return count(array_filter(
            $this->checks,
            static fn(GarIntegrityCheckResult $check): bool => !$check->isAvailable(),
        ));
    }
}
