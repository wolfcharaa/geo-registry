<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry;

use App\Module\AddressRegistry\Feature\CoordinateEnrichment\Capability\AddressCoordinatesResolved;
use App\Module\AddressRegistry\Feature\CoordinateEnrichment\Infrastructure\AddressCoordinatesResolvedCodec;
use Fedor\MessageBus\Codec\JsonValue;
use Testo\Assert;
use Testo\Test;

#[Test]
final class AddressCoordinatesResolvedCodecTest
{
    public function testItRoundTripsTheStableCoordinateEventContract(): void
    {
        $codec = new AddressCoordinatesResolvedCodec();
        $event = new AddressCoordinatesResolved(
            objectId: 770005,
            lat: 55.750001,
            lon: 37.610001,
            displayName: 'Регион Альфа, Тестоград, Общая улица, 10',
            osmType: 'way',
            osmId: 770005,
            source: 'nominatim',
            regionCode: 77,
        );

        $decoded = $codec->fromPayload($codec->toPayload($event));

        Assert::same($codec->alias(), 'gar.address_coordinates.resolved');
        Assert::same($codec->version(), 1);
        Assert::equals($decoded, $event);
    }

    public function testItDecodesTheLegacyPayloadWithoutNewOptionalFields(): void
    {
        $event = (new AddressCoordinatesResolvedCodec())->fromPayload(JsonValue::from([
            'objectId' => 770005,
            'lat' => 55.750001,
            'lon' => 37.610001,
            'displayName' => null,
            'osmType' => null,
            'osmId' => null,
        ]));

        Assert::same($event->source, 'external');
        Assert::same($event->regionCode, null);
    }
}
