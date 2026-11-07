<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\Integrity;

interface GarIntegrityChecker
{
    /** @param non-empty-list<int> $regions */
    public function check(array $regions, bool $deep): GarIntegrityReport;
}
