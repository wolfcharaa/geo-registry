<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\OpenSearch\Infrastructure;

use function count;
use function preg_match;
use function preg_split;
use function trim;

final class OpenSearchAddressQueryIntent
{
    public function __construct(
        private readonly OpenSearchAddressComponentParser $components = new OpenSearchAddressComponentParser(),
    ) {}

    public function targetsHouse(string $query): bool
    {
        return $this->houseNumber($query) !== null && !$this->containsUnitMarker($query);
    }

    public function houseKey(string $query): ?string
    {
        if (!$this->targetsHouse($query)) {
            return null;
        }

        $houseNumber = $this->houseNumber($query);

        return $houseNumber !== null ? $this->components->markerValueKey($houseNumber) : null;
    }

    private function houseNumber(string $query): ?string
    {
        if (preg_match(
            '/(?:^|[,\s])(?:д|дом)\.?\s*(\d+(?:[\/-]\d+)?(?:[а-яa-z])?)(?=$|[, ]|\s+(?:к|корп|корпус|стр|строение|соор|сооружение|вл|владение|помещ|помещение)\.?)/iu',
            $query,
            $match,
        ) === 1) {
            return $match[1];
        }

        $words = preg_split('/\s+/u', trim($query));
        if ($words === false || count($words) < 2) {
            return null;
        }

        if (preg_match('/(?:^|[,\s])(\d+(?:[\/-]\d+)?(?:[а-яa-z])?)\s*$/iu', $query, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    private function containsUnitMarker(string $query): bool
    {
        return preg_match('/(?:^|[,\s])(?:кв|квартира|пом|помещ|помещение|комн|комната|офис)\.?/iu', $query) === 1;
    }
}
