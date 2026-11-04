<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\ReverseGeocode\Infrastructure;

use Amp\Cancellation;
use Amp\CancelledException;
use App\Infrastructure\Nominatim\NominatimClient;
use App\Module\AddressRegistry\Feature\ReverseGeocode\Capability\ReverseGeocodeLocation;
use App\Module\AddressRegistry\Feature\ReverseGeocode\Capability\ReverseGeocodePort;
use App\Module\AddressRegistry\Feature\ReverseGeocode\Capability\ReverseGeocodeUnavailable;
use Throwable;

final readonly class NominatimReverseGeocodeAdapter implements ReverseGeocodePort
{
    public function __construct(private NominatimClient $client) {}

    public function locate(
        float $latitude,
        float $longitude,
        Cancellation $cancellation,
    ): ?ReverseGeocodeLocation {
        try {
            $payload = $this->client->reverse($latitude, $longitude, $cancellation);

            return self::location($payload);
        } catch (CancelledException $exception) {
            throw $exception;
        } catch (ReverseGeocodeUnavailable $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ReverseGeocodeUnavailable($exception);
        }
    }

    /** @param array<string, mixed> $payload */
    private static function location(array $payload): ?ReverseGeocodeLocation
    {
        if (is_string($payload['error'] ?? null)) {
            return null;
        }

        $displayName = $payload['display_name'] ?? null;
        $latitude = self::coordinate($payload['lat'] ?? null, -90, 90);
        $longitude = self::coordinate($payload['lon'] ?? null, -180, 180);
        if (!is_string($displayName) || trim($displayName) === '' || $latitude === null || $longitude === null) {
            throw new ReverseGeocodeUnavailable();
        }

        return new ReverseGeocodeLocation(
            displayName: trim($displayName),
            latitude: $latitude,
            longitude: $longitude,
            sourceType: self::sourceType($payload['osm_type'] ?? null),
            sourceId: self::sourceId($payload['osm_id'] ?? $payload['place_id'] ?? null),
        );
    }

    private static function coordinate(mixed $value, float $minimum, float $maximum): ?float
    {
        if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value)) {
            return null;
        }

        $coordinate = (float) $value;

        return is_finite($coordinate) && $coordinate >= $minimum && $coordinate <= $maximum
            ? $coordinate
            : null;
    }

    private static function sourceType(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || trim($value) === '') {
            throw new ReverseGeocodeUnavailable();
        }

        return trim($value);
    }

    private static function sourceId(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        throw new ReverseGeocodeUnavailable();
    }
}
