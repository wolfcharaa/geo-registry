<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\Status;

interface GarStatusReader
{
    /** @param non-empty-list<int> $regions */
    public function read(array $regions, bool $exact): GarStatusSnapshot;
}
