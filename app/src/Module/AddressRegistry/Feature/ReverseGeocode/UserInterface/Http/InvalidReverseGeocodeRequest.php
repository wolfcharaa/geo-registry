<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\ReverseGeocode\UserInterface\Http;

use InvalidArgumentException;

final class InvalidReverseGeocodeRequest extends InvalidArgumentException
{
    /** @param array<string, float|int|string|null> $query */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $query,
    ) {
        parent::__construct($message);
    }
}
