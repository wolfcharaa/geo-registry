<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\CoordinateEnrichment\Infrastructure;

use App\Module\AddressRegistry\Feature\CoordinateEnrichment\Capability\AddressCoordinatesResolved;
use Fedor\MessageBus\Codec\InvalidMessagePayload;
use Fedor\MessageBus\Codec\JsonValue;
use Fedor\MessageBus\Codec\MessageCodecInterface;
use Fedor\MessageBus\Message\Command;
use Fedor\MessageBus\Message\Event;
use Fedor\MessageBus\Message\Query;

final class AddressCoordinatesResolvedCodec implements MessageCodecInterface
{
    public function messageClass(): string
    {
        return AddressCoordinatesResolved::class;
    }

    public function alias(): string
    {
        return 'gar.address_coordinates.resolved';
    }

    public function version(): int
    {
        return 1;
    }

    /** @param Query<mixed>|Command|Event $message */
    public function toPayload(Query|Command|Event $message): JsonValue
    {
        if (!$message instanceof AddressCoordinatesResolved) {
            throw new InvalidMessagePayload('Unexpected address coordinates message.');
        }

        return JsonValue::from([
            'objectId' => $message->objectId,
            'lat' => $message->lat,
            'lon' => $message->lon,
            'displayName' => $message->displayName,
            'osmType' => $message->osmType,
            'osmId' => $message->osmId,
            'source' => $message->source,
            'regionCode' => $message->regionCode,
        ]);
    }

    public function fromPayload(JsonValue $payload): AddressCoordinatesResolved
    {
        $data = $payload->value();
        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidMessagePayload('Address coordinates payload must be an object.');
        }

        return new AddressCoordinatesResolved(
            objectId: self::integer($data, 'objectId'),
            lat: self::number($data, 'lat'),
            lon: self::number($data, 'lon'),
            displayName: self::nullableString($data, 'displayName'),
            osmType: self::nullableString($data, 'osmType'),
            osmId: self::nullableInteger($data, 'osmId'),
            source: self::optionalString($data, 'source', 'external'),
            regionCode: self::nullableInteger($data, 'regionCode'),
        );
    }

    /** @param array<array-key, mixed> $data */
    private static function integer(array $data, string $field): int
    {
        $value = $data[$field] ?? null;
        if (!is_int($value)) {
            throw new InvalidMessagePayload("Address coordinates field `{$field}` must be an integer.");
        }

        return $value;
    }

    /** @param array<array-key, mixed> $data */
    private static function nullableInteger(array $data, string $field): ?int
    {
        $value = $data[$field] ?? null;
        if ($value !== null && !is_int($value)) {
            throw new InvalidMessagePayload("Address coordinates field `{$field}` must be a nullable integer.");
        }

        return $value;
    }

    /** @param array<array-key, mixed> $data */
    private static function number(array $data, string $field): float
    {
        $value = $data[$field] ?? null;
        if (!is_int($value) && !is_float($value)) {
            throw new InvalidMessagePayload("Address coordinates field `{$field}` must be numeric.");
        }

        return (float) $value;
    }

    /** @param array<array-key, mixed> $data */
    private static function nullableString(array $data, string $field): ?string
    {
        $value = $data[$field] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new InvalidMessagePayload("Address coordinates field `{$field}` must be a nullable string.");
        }

        return $value;
    }

    /** @param array<array-key, mixed> $data */
    private static function optionalString(array $data, string $field, string $default): string
    {
        $value = $data[$field] ?? $default;
        if (!is_string($value)) {
            throw new InvalidMessagePayload("Address coordinates field `{$field}` must be a string.");
        }

        return $value;
    }
}
