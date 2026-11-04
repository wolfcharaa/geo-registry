<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\ReverseGeocode\UserInterface\Http;

use Amp\Http\Server\Request;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class ReverseGeocodeRequestMapper
{
    public function __construct(private ValidatorInterface $validator) {}

    public function map(Request $request): ReverseGeocodeRequest
    {
        parse_str($request->getUri()->getQuery(), $parameters);
        if (!array_key_exists('lat', $parameters) || !array_key_exists('lon', $parameters)) {
            throw new InvalidReverseGeocodeRequest(
                'missing_coordinates',
                'Missing required parameters: lat and lon',
                self::safeQuery($parameters),
            );
        }

        $input = new ReverseGeocodeRequest(
            latitude: $parameters['lat'],
            longitude: $parameters['lon'],
            hierarchy: $parameters['hier'] ?? 'adm',
            limit: $parameters['num'] ?? 1,
        );
        $violations = $this->validator->validate($input);
        if ($violations->count() === 0) {
            return $input;
        }

        $violation = $violations->get(0);
        $field = $violation->getPropertyPath();

        throw new InvalidReverseGeocodeRequest(
            self::errorCode($field),
            (string) $violation->getMessage(),
            self::safeQuery($parameters),
        );
    }

    /**
     * @param array<array-key, mixed> $parameters
     * @return array<string, float|int|string|null>
     */
    private static function safeQuery(array $parameters): array
    {
        return [
            'lat' => self::safeCoordinate($parameters['lat'] ?? null),
            'lon' => self::safeCoordinate($parameters['lon'] ?? null),
            'hierarchy' => is_string($parameters['hier'] ?? null) ? $parameters['hier'] : 'adm',
            'limit' => is_string($parameters['num'] ?? null) && ctype_digit($parameters['num'])
                ? (int) $parameters['num']
                : 1,
        ];
    }

    private static function safeCoordinate(mixed $value): ?float
    {
        return (is_int($value) || is_float($value) || is_string($value)) && is_numeric($value)
            ? (float) $value
            : null;
    }

    private static function errorCode(string $field): string
    {
        return match ($field) {
            'latitude' => 'invalid_latitude',
            'longitude' => 'invalid_longitude',
            'hierarchy' => 'invalid_hierarchy',
            'limit' => 'invalid_limit',
            default => 'invalid_coordinates',
        };
    }
}
