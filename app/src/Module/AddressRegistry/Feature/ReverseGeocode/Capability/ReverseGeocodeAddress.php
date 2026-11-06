<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\ReverseGeocode\Capability;

final readonly class ReverseGeocodeAddress
{
    public function __construct(
        public string $query,
        public string $route,
        public string $houseNumber,
    ) {}
}
