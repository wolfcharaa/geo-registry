<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\ReverseGeocode\Capability;

use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressMatch;

final readonly class ReverseGeocodeResult
{
    /** @param list<SearchAddressMatch> $matches */
    private function __construct(
        public array $matches,
        public ?ReverseGeocodeLocation $fallbackLocation,
    ) {}

    public static function empty(): self
    {
        return new self([], null);
    }

    /** @param list<SearchAddressMatch> $matches */
    public static function matched(array $matches): self
    {
        return new self($matches, null);
    }

    public static function fallback(ReverseGeocodeLocation $location): self
    {
        return new self([], $location);
    }

    public function count(): int
    {
        return count($this->matches) + ($this->fallbackLocation === null ? 0 : 1);
    }
}
