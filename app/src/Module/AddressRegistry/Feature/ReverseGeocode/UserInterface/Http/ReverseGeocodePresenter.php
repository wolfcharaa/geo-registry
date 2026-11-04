<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\ReverseGeocode\UserInterface\Http;

use App\Module\AddressRegistry\Capability\AddressHierarchy;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressMatch;
use App\Module\AddressRegistry\Feature\ReverseGeocode\Capability\ReverseGeocodeLocation;
use App\Module\AddressRegistry\Feature\ReverseGeocode\Capability\ReverseGeocodeResult;
use App\Module\AddressRegistry\UserInterface\Http\AddressSuggestionPresenter;

final readonly class ReverseGeocodePresenter
{
    public function __construct(private AddressSuggestionPresenter $documents) {}

    /**
     * @param array<string, float|int|string> $query
     * @return array<string, mixed>
     */
    public function success(
        ReverseGeocodeResult $result,
        array $query,
        AddressHierarchy $hierarchy,
    ): array {
        $suggestions = array_map($this->document(...), $result->matches);
        if ($result->fallbackLocation !== null) {
            $suggestions[] = self::fallback($result->fallbackLocation, $hierarchy);
        }

        return [
            'success' => true,
            'count' => $result->count(),
            'query' => $query,
            'suggestions' => $suggestions,
            'meta' => ['endpoint' => 'reverse-geocode'],
        ];
    }

    /**
     * @param array<string, float|int|string|null> $query
     * @return array<string, mixed>
     */
    public function error(string $message, string $code, array $query): array
    {
        return [
            'success' => false,
            'count' => 0,
            'query' => $query,
            'suggestions' => [],
            'meta' => ['endpoint' => 'reverse-geocode'],
            'error' => $message,
            'error_code' => $code,
        ];
    }

    /** @return array<string, mixed> */
    private function document(SearchAddressMatch $match): array
    {
        return $this->documents->present(
            document: $match->document,
            score: $match->score,
            aliases: $match->aliases,
            matchedAlias: $match->matchedAlias,
            backend: $match->backend,
        );
    }

    /** @return array<string, mixed> */
    private static function fallback(ReverseGeocodeLocation $location, AddressHierarchy $hierarchy): array
    {
        return [
            'value' => $location->displayName,
            'unrestricted_value' => $location->displayName,
            'data' => [
                'fias_id' => null,
                'fias_uuid' => null,
                'object_id' => null,
                'object_guid' => null,
                'postal_code' => null,
                'okato' => null,
                'oktmo' => null,
                'geo_lat' => $location->latitude,
                'geo_lon' => $location->longitude,
                'hierarchy_type' => $hierarchy->value,
                'level' => null,
                'score' => null,
                'search_backend' => 'nominatim',
                'source' => 'nominatim',
                'source_id' => $location->sourceId,
                'source_type' => $location->sourceType,
                'aliases' => [],
                'alias_count' => 0,
                'matched_alias' => null,
            ],
        ];
    }
}
