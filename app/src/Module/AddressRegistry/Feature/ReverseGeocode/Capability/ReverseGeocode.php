<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\ReverseGeocode\Capability;

use App\Module\AddressRegistry\Capability\AddressHierarchy;
use Fedor\MessageBus\Message\Query;

/** @implements Query<ReverseGeocodeResult> */
final readonly class ReverseGeocode implements Query
{
    public int $limit;

    public function __construct(
        public float $latitude,
        public float $longitude,
        public AddressHierarchy $hierarchy = AddressHierarchy::Administrative,
        int $limit = 1,
    ) {
        $this->limit = max(1, min(20, $limit));
    }
}
