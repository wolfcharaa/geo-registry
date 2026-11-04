<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\CoordinateEnrichment\Capability;

use Fedor\MessageBus\Attribute\MessageAlias;
use Fedor\MessageBus\Message\Event;
use InvalidArgumentException;

#[MessageAlias('gar.address_coordinates.resolved')]
final readonly class AddressCoordinatesResolved implements Event
{
    public function __construct(
        public int $objectId,
        public float $lat,
        public float $lon,
        public ?string $displayName = null,
        public ?string $osmType = null,
        public ?int $osmId = null,
        public string $source = 'external',
        public ?int $regionCode = null,
    ) {
        if ($this->objectId <= 0) {
            throw new InvalidArgumentException('Address object ID must be positive.');
        }
        if (!is_finite($this->lat) || $this->lat < -90 || $this->lat > 90) {
            throw new InvalidArgumentException('Address latitude must be between -90 and 90.');
        }
        if (!is_finite($this->lon) || $this->lon < -180 || $this->lon > 180) {
            throw new InvalidArgumentException('Address longitude must be between -180 and 180.');
        }
        if ($this->displayName !== null && trim($this->displayName) === '') {
            throw new InvalidArgumentException('Address geocode display name must be null or non-empty.');
        }
        if ($this->osmType !== null && (trim($this->osmType) === '' || strlen($this->osmType) > 16)) {
            throw new InvalidArgumentException('Address OSM type must be null or contain at most 16 bytes.');
        }
        if ($this->osmId !== null && $this->osmId <= 0) {
            throw new InvalidArgumentException('Address OSM ID must be null or positive.');
        }
        if (trim($this->source) === '' || strlen($this->source) > 32) {
            throw new InvalidArgumentException('Address coordinate source must contain at most 32 bytes.');
        }
        if ($this->regionCode !== null && ($this->regionCode < 1 || $this->regionCode > 99)) {
            throw new InvalidArgumentException('Address region code must be null or between 1 and 99.');
        }
    }
}
