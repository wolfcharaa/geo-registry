<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\ReverseGeocode\Capability;

use RuntimeException;
use Throwable;

final class ReverseGeocodeUnavailable extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('Reverse geocoding is unavailable.', previous: $previous);
    }
}
