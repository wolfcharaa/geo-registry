<?php

declare(strict_types=1);

namespace App\Tests\Scenario\Module\AddressRegistry\ReverseGeocode;

use Amp\Cancellation;
use App\Application\DependencyInjection\ApplicationMessageBusModule;
use App\Application\MessageBus\MessageBusRegistrySource;
use App\Application\Observability\CorrelationContext;
use App\Module\AddressRegistry\Capability\AddressDocument;
use App\Module\AddressRegistry\Capability\AddressHierarchy;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressCriteria;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressHandler;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressMatch;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressPort;
use App\Module\AddressRegistry\Feature\ReverseGeocode\Capability\MissingAddressCoordinatesPublisher;
use App\Module\AddressRegistry\Feature\ReverseGeocode\Capability\ReverseGeocode;
use App\Module\AddressRegistry\Feature\ReverseGeocode\Capability\ReverseGeocodeHandler;
use App\Module\AddressRegistry\Feature\ReverseGeocode\Capability\ReverseGeocodeLocation;
use App\Module\AddressRegistry\Feature\ReverseGeocode\Capability\ReverseGeocodePort;
use App\Module\AddressRegistry\Feature\ReverseGeocode\Capability\ReverseGeocodeResult;
use Fedor\MessageBus\Context\MessageContextInterface;
use Fedor\MessageBus\MessageBus;
use Psr\Log\NullLogger;
use Testo\Assert;
use Testo\Test;
use Thesis\Dic;
use Thesis\Dic\Module;
use Thesis\Dic\Ref;

#[Test]
final class ReverseGeocodeScenarioTest
{
    public function testItMatchesTheTypedLocationThroughThePublicSearchScenario(): void
    {
        $port = new RecordingReverseGeocodePort(withMatch: true);

        $result = Dic::run(
            new ReverseGeocodeScenarioModule($port),
            static fn(MessageBus $messageBus): ReverseGeocodeResult => $messageBus->ask(new ReverseGeocode(
                latitude: 55.750001,
                longitude: 37.610001,
                hierarchy: AddressHierarchy::Municipal,
                limit: 3,
            )),
        );

        Assert::same($port->coordinates, [[55.750001, 37.610001]]);
        Assert::notNull($port->searchCriteria);
        Assert::same($port->searchCriteria->query, 'Регион Альфа, Тестоград, Общая улица, 10');
        Assert::same($port->searchCriteria->hierarchy, AddressHierarchy::Municipal);
        Assert::same($port->searchCriteria->limit, 3);
        Assert::same(count($result->matches), 1);
        Assert::same($result->fallbackLocation, null);
        Assert::same($port->publishedObjectIds, [770005]);
    }

    public function testItKeepsNominatimFallbackOutsideTheCoreAddressDocument(): void
    {
        $port = new RecordingReverseGeocodePort(withMatch: false);

        $result = Dic::run(
            new ReverseGeocodeScenarioModule($port),
            static fn(MessageBus $messageBus): ReverseGeocodeResult => $messageBus->ask(new ReverseGeocode(
                latitude: 55.750001,
                longitude: 37.610001,
            )),
        );

        Assert::same($result->matches, []);
        Assert::notNull($result->fallbackLocation);
        Assert::same($result->fallbackLocation->sourceType, 'way');
        Assert::same($result->fallbackLocation->sourceId, 770005);
        Assert::same($port->publishedObjectIds, []);
    }

    public function testItDoesNotPublishCoordinatesAlreadyStoredOnTheAddress(): void
    {
        $port = new RecordingReverseGeocodePort(withMatch: true, documentHasCoordinates: true);

        Dic::run(
            new ReverseGeocodeScenarioModule($port),
            static fn(MessageBus $messageBus): ReverseGeocodeResult => $messageBus->ask(new ReverseGeocode(
                latitude: 55.750001,
                longitude: 37.610001,
            )),
        );

        Assert::same($port->publishedObjectIds, []);
    }
}

/** @implements Module<Ref<MessageBus>> */
final readonly class ReverseGeocodeScenarioModule implements Module
{
    public function __construct(private RecordingReverseGeocodePort $port) {}

    /** @return Ref<MessageBus> */
    public function configure(Dic $dic): Ref
    {
        $port = $dic->value($this->port);
        $services = [
            ReverseGeocodeHandler::class => $dic
                ->object(ReverseGeocodeHandler::class)
                ->args(['locator' => $port, 'coordinates' => $port])
                ->canBeScoped(),
            SearchAddressHandler::class => $dic
                ->object(SearchAddressHandler::class)
                ->arg('reader', $port)
                ->canBeScoped(),
        ];

        return $dic->import(new ApplicationMessageBusModule(
            new MessageBusRegistrySource(
                classes: array_keys($services),
                services: [
                    ReverseGeocodeHandler::class => ReverseGeocodeHandler::class,
                    SearchAddressHandler::class => SearchAddressHandler::class,
                ],
                flows: MessageBusRegistrySource::empty()->flows,
            ),
            $dic->object(CorrelationContext::class),
            $dic->value(new NullLogger()),
            $services,
        ));
    }
}

final class RecordingReverseGeocodePort implements
    ReverseGeocodePort,
    SearchAddressPort,
    MissingAddressCoordinatesPublisher
{
    /** @var list<array{float, float}> */
    public array $coordinates = [];

    public ?SearchAddressCriteria $searchCriteria = null;

    /** @var list<int> */
    public array $publishedObjectIds = [];

    public function __construct(
        private readonly bool $withMatch,
        private readonly bool $documentHasCoordinates = false,
    ) {}

    public function locate(
        float $latitude,
        float $longitude,
        Cancellation $cancellation,
    ): ReverseGeocodeLocation {
        $cancellation->throwIfRequested();
        $this->coordinates[] = [$latitude, $longitude];

        return new ReverseGeocodeLocation(
            displayName: 'Регион Альфа, Тестоград, Общая улица, 10',
            latitude: 55.750001,
            longitude: 37.610001,
            sourceType: 'way',
            sourceId: 770005,
        );
    }

    public function search(SearchAddressCriteria $criteria, Cancellation $cancellation): array
    {
        $cancellation->throwIfRequested();
        $this->searchCriteria = $criteria;
        if (!$this->withMatch) {
            return [];
        }

        return [new SearchAddressMatch(
            document: new AddressDocument(
                objectId: 770005,
                objectGuid: '00000000-0000-4000-8000-000000770005',
                address: 'Регион Альфа, г. Тестоград, ул. Общая, д. 10',
                hierarchy: $criteria->hierarchy,
                level: 8,
                postalCode: '770001',
                okato: '77001000000',
                oktmo: '77001001000',
                latitude: $this->documentHasCoordinates ? 55.750001 : null,
                longitude: $this->documentHasCoordinates ? 37.610001 : null,
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
            ),
            score: 1.0,
            aliases: [],
            matchedAlias: null,
        )];
    }

    public function publishIfMissing(
        AddressDocument $document,
        ReverseGeocodeLocation $location,
        MessageContextInterface $context,
    ): void {
        $context->cancellation()->throwIfRequested();
        if ($document->latitude === null || $document->longitude === null) {
            $this->publishedObjectIds[] = $document->objectId;
        }
    }
}
