<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\ReverseGeocode\Capability;

final readonly class ReverseGeocodeLocation
{
    public function __construct(
        public string $displayName,
        public float $latitude,
        public float $longitude,
        public ?string $sourceType,
        public ?int $sourceId,
        public ?ReverseGeocodeAddress $address = null,
    ) {}
}
