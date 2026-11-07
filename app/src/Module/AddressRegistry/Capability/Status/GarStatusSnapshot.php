<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\Status;

final readonly class GarStatusSnapshot
{
    /** @param list<GarRegionStatus> $regions */
    public function __construct(
        public ?string $garVersion,
        public ?string $garVersionDate,
        public ?GarImportStatus $import,
        public ?AddressSearchRebuildStatus $rebuild,
        public array $regions,
        public bool $exact,
    ) {}
}
