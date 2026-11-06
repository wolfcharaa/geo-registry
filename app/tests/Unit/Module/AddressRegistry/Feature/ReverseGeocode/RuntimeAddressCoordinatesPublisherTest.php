<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry\Feature\ReverseGeocode;

use Amp\Cancellation;
use Amp\NullCancellation;
use App\Module\AddressRegistry\Capability\AddressDocument;
use App\Module\AddressRegistry\Capability\AddressHierarchy;
use App\Module\AddressRegistry\Feature\CoordinateEnrichment\Capability\AddressCoordinatesResolved;
use App\Module\AddressRegistry\Feature\ReverseGeocode\Capability\ReverseGeocodeLocation;
use App\Module\AddressRegistry\Feature\ReverseGeocode\Infrastructure\RuntimeAddressCoordinatesPublisher;
use Fedor\MessageBus\Context\MessageContextInterface;
use Fedor\MessageBus\Envelope\Envelope;
use Fedor\MessageBus\Execution\ExecutionOptions;
use Fedor\MessageBus\Message\Command;
use Fedor\MessageBus\Message\Event;
use Fedor\MessageBus\Message\Query;
use Fedor\MessageBus\Publication\PublishResult;
use LogicException;
use Psr\Log\NullLogger;
use Testo\Assert;
use Testo\Test;

#[Test]
final class RuntimeAddressCoordinatesPublisherTest
{
    public function testItPublishesTheRegionalStableEventOnlyForMissingCoordinates(): void
    {
        $context = new RecordingCoordinateMessageContext();
        $publisher = new RuntimeAddressCoordinatesPublisher(new NullLogger());
        $location = new ReverseGeocodeLocation(
            displayName: 'Регион Альфа, Тестоград, Общая улица, 10',
            latitude: 55.750001,
            longitude: 37.610001,
            sourceType: 'way',
            sourceId: 770005,
        );

        $publisher->publishIfMissing(self::document(null, null), $location, $context);
        $publisher->publishIfMissing(self::document(55.75, 37.61), $location, $context);

        Assert::same(count($context->events), 1);
        $event = $context->events[0] ?? null;
        Assert::true($event instanceof AddressCoordinatesResolved);
        Assert::same($event->objectId, 770005);
        Assert::same($event->regionCode, 50);
        Assert::same($event->source, 'nominatim');
        Assert::same($event->osmType, 'way');
        Assert::same($event->osmId, 770005);
    }

    private static function document(?float $latitude, ?float $longitude): AddressDocument
    {
        return new AddressDocument(
            objectId: 770005,
            objectGuid: '00000000-0000-4000-8000-000000770005',
            address: 'Регион Альфа, г. Тестоград, ул. Общая, д. 10',
            hierarchy: AddressHierarchy::Administrative,
            level: 8,
            postalCode: '770001',
            okato: '46001000000',
            oktmo: '77001001000',
            latitude: $latitude,
            longitude: $longitude,
            source: 'gar',
            sourceId: null,
            dataMosGlobalId: null,
            dataMosUnom: null,
            dataMosNreg: null,
            dataMosAdministrativeArea: null,
            dataMosDistrict: null,
            cadastralNumbers: [],
            landCadastralNumbers: [],
            aliasCount: 0,
            regionCode: 50,
        );
    }
}

final class RecordingCoordinateMessageContext implements MessageContextInterface
{
    /** @var list<Event> */
    public array $events = [];

    public function cancellation(): Cancellation
    {
        return new NullCancellation();
    }

    public function envelope(): Envelope
    {
        throw new LogicException('The publisher must not inspect envelope internals.');
    }

    public function ask(Query $query, ?ExecutionOptions $options = null): mixed
    {
        throw new LogicException('The publisher must not dispatch queries.');
    }

    public function dispatch(Command $command, ?ExecutionOptions $options = null): void
    {
        throw new LogicException('The publisher must not dispatch commands.');
    }

    public function publish(Event $event, ?ExecutionOptions $options = null): PublishResult
    {
        $this->events[] = $event;

        return PublishResult::empty();
    }
}
