<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\ImportGar;

interface GarImportPlanner
{
    /** @param list<int> $regions */
    public function plan(string $sourcePath, array $regions): GarImportPlan;
}
