<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\ReverseGeocode\UserInterface\Http;

use App\Module\AddressRegistry\Capability\AddressHierarchy;
use LogicException;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ReverseGeocodeRequest
{
    public function __construct(
        #[Assert\NotNull(message: 'lat is required.')]
        #[Assert\Type(type: 'numeric', message: 'lat must be numeric.')]
        #[Assert\Range(min: -90, max: 90, notInRangeMessage: 'lat must be between -90 and 90.')]
        public mixed $latitude,
        #[Assert\NotNull(message: 'lon is required.')]
        #[Assert\Type(type: 'numeric', message: 'lon must be numeric.')]
        #[Assert\Range(min: -180, max: 180, notInRangeMessage: 'lon must be between -180 and 180.')]
        public mixed $longitude,
        #[Assert\Type(type: 'string', message: 'hier must be a string.')]
        #[Assert\Choice(choices: ['adm', 'mun'], message: 'hier must be adm or mun.')]
        public mixed $hierarchy = 'adm',
        #[Assert\NotNull(message: 'num must be an integer.')]
        #[Assert\Type(type: ['integer', 'digit'], message: 'num must be an integer.')]
        #[Assert\Range(min: 1, max: 20, notInRangeMessage: 'num must be between 1 and 20.')]
        public mixed $limit = 1,
    ) {}

    public function normalizedLatitude(): float
    {
        return $this->coordinate($this->latitude);
    }

    public function normalizedLongitude(): float
    {
        return $this->coordinate($this->longitude);
    }

    public function normalizedHierarchy(): AddressHierarchy
    {
        if (!is_string($this->hierarchy)) {
            throw new LogicException('ReverseGeocodeRequest must be validated before normalization.');
        }

        return AddressHierarchy::from($this->hierarchy);
    }

    public function normalizedLimit(): int
    {
        if (is_int($this->limit)) {
            return $this->limit;
        }
        if (is_string($this->limit) && ctype_digit($this->limit)) {
            return (int) $this->limit;
        }

        throw new LogicException('ReverseGeocodeRequest must be validated before normalization.');
    }

    /** @return array{lat: float, lon: float, hierarchy: string, limit: int} */
    public function queryContext(): array
    {
        return [
            'lat' => $this->normalizedLatitude(),
            'lon' => $this->normalizedLongitude(),
            'hierarchy' => $this->normalizedHierarchy()->value,
            'limit' => $this->normalizedLimit(),
        ];
    }

    private function coordinate(mixed $value): float
    {
        if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value)) {
            throw new LogicException('ReverseGeocodeRequest must be validated before normalization.');
        }

        return (float) $value;
    }
}
