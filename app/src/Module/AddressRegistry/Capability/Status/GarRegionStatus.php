<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\Status;

final readonly class GarRegionStatus
{
    public function __construct(
        public int $regionCode,
        public string $hierarchy,
        public string $checkpointStatus,
        public int $checkpointRows,
        public ?int $projectionRows,
        public ?int $geocodedRows,
        public ?int $dataMosRows,
        public ?int $aliasRows,
    ) {}
}
