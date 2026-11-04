<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\ReverseGeocode\Capability;

use Amp\Cancellation;

interface ReverseGeocodePort
{
    public function locate(
        float $latitude,
        float $longitude,
        Cancellation $cancellation,
    ): ?ReverseGeocodeLocation;
}
