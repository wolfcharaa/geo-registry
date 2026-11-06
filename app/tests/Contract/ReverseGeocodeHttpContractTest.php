<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use function Amp\ByteStream\buffer;

use Amp\Cancellation;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use App\Application\DependencyInjection\ApplicationMessageBusModule;
use App\Application\MessageBus\MessageBusRegistrySource;
use App\Application\Observability\CorrelationContext;
use App\Module\AddressRegistry\Capability\AddressDocument;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressCriteria;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressHandler;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressMatch;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressPort;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressUnavailable;
use App\Module\AddressRegistry\Feature\ReverseGeocode\Capability\MissingAddressCoordinatesPublisher;
use App\Module\AddressRegistry\Feature\ReverseGeocode\Capability\ReverseGeocodeAddress;
use App\Module\AddressRegistry\Feature\ReverseGeocode\Capability\ReverseGeocodeHandler;
use App\Module\AddressRegistry\Feature\ReverseGeocode\Capability\ReverseGeocodeLocation;
use App\Module\AddressRegistry\Feature\ReverseGeocode\Capability\ReverseGeocodePort;
use App\Module\AddressRegistry\Feature\ReverseGeocode\Capability\ReverseGeocodeUnavailable;
use App\Module\AddressRegistry\Feature\ReverseGeocode\UserInterface\Http\ReverseGeocodeAction;
use App\Module\AddressRegistry\Feature\ReverseGeocode\UserInterface\Http\ReverseGeocodePresenter;
use App\Module\AddressRegistry\Feature\ReverseGeocode\UserInterface\Http\ReverseGeocodeRequestMapper;
use App\Module\AddressRegistry\UserInterface\Http\AddressSuggestionPresenter;
use App\Tests\Support\Http\TestClient;
use Fedor\MessageBus\Context\MessageContextInterface;
use Fedor\MessageBus\MessageBus;
use League\Uri\Http;
use Psr\Log\NullLogger;
use Symfony\Component\Validator\Validation;
use Testo\Assert;
use Testo\Test;
use Thesis\Dic;
use Thesis\Dic\Module;
use Thesis\Dic\Ref;

#[Test]
final class ReverseGeocodeHttpContractTest
{
    public function testItPresentsTheMatchedGarAddress(): void
    {
        $response = $this->response([
            'lat' => '55.750001',
            'lon' => '37.610001',
            'hier' => 'adm',
            'num' => '1',
        ]);
        $payload = $this->payload($response);
        $suggestions = $this->array($payload['suggestions'] ?? null);
        $suggestion = $this->array($suggestions[0] ?? null);
        $data = $this->array($suggestion['data'] ?? null);

        Assert::same($response->getStatus(), 200);
        Assert::same($payload['success'] ?? null, true);
        Assert::same($payload['count'] ?? null, 1);
        Assert::same($payload['query'] ?? null, [
            'lat' => 55.750001,
            'lon' => 37.610001,
            'hierarchy' => 'adm',
            'limit' => 1,
        ]);
        Assert::same($payload['meta'] ?? null, ['endpoint' => 'reverse-geocode']);
        Assert::same($data['object_id'] ?? null, 770005);
        Assert::same($data['source'] ?? null, 'gar');
        Assert::same($data['search_backend'] ?? null, 'postgres');
    }

    public function testItPresentsNominatimAsASeparateFallbackSuggestion(): void
    {
        $response = $this->response(['lat' => '55.750001', 'lon' => '37.610001'], 'fallback');
        $payload = $this->payload($response);
        $suggestions = $this->array($payload['suggestions'] ?? null);
        $suggestion = $this->array($suggestions[0] ?? null);
        $data = $this->array($suggestion['data'] ?? null);

        Assert::same($response->getStatus(), 200);
        Assert::same($payload['count'] ?? null, 1);
        Assert::true(array_key_exists('object_id', $data));
        Assert::true(array_key_exists('object_guid', $data));
        Assert::same($data['object_id'], null);
        Assert::same($data['object_guid'], null);
        Assert::same($data['source'] ?? null, 'nominatim');
        Assert::same($data['search_backend'] ?? null, 'nominatim');
        Assert::same($data['source_type'] ?? null, 'way');
        Assert::same($data['source_id'] ?? null, 770005);
    }

    public function testItPresentsStableValidationAndUnavailableErrors(): void
    {
        $missing = $this->payload($this->response([]));
        $invalid = $this->payload($this->response(['lat' => '91', 'lon' => '37.610001']));
        $nominatimUnavailable = $this->response(
            ['lat' => '55.750001', 'lon' => '37.610001'],
            'nominatim-unavailable',
        );
        $searchUnavailable = $this->response(
            ['lat' => '55.750001', 'lon' => '37.610001'],
            'search-unavailable',
        );

        Assert::same($missing['error_code'] ?? null, 'missing_coordinates');
        Assert::same($missing['query'] ?? null, [
            'lat' => null,
            'lon' => null,
            'hierarchy' => 'adm',
            'limit' => 1,
        ]);
        Assert::same($invalid['error_code'] ?? null, 'invalid_latitude');
        Assert::same($nominatimUnavailable->getStatus(), 503);
        Assert::same($searchUnavailable->getStatus(), 503);
        Assert::same(
            $this->payload($nominatimUnavailable)['error_code'] ?? null,
            'geocoding_unavailable',
        );
        Assert::same($this->payload($searchUnavailable)['suggestions'] ?? null, []);
    }

    /** @param array<string, string> $parameters */
    private function response(array $parameters, string $mode = 'match'): Response
    {
        return Dic::run(
            new ContractReverseGeocodeModule(new ContractReverseGeocodePort($mode)),
            fn(MessageBus $messageBus): Response => $this
                ->action($messageBus)
                ->handle($this->request($parameters)),
        );
    }

    private function action(MessageBus $messageBus): ReverseGeocodeAction
    {
        return new ReverseGeocodeAction(
            new ReverseGeocodeRequestMapper(
                Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
            ),
            new ReverseGeocodePresenter(new AddressSuggestionPresenter()),
            $messageBus,
            10,
        );
    }

    /** @param array<string, string> $parameters */
    private function request(array $parameters): Request
    {
        $query = http_build_query($parameters);

        return new Request(
            new TestClient(),
            'GET',
            Http::new('http://localhost/api/addresses/reverse-geocode' . ($query === '' ? '' : '?' . $query)),
        );
    }

    /** @return array<array-key, mixed> */
    private function payload(Response $response): array
    {
        return $this->array(json_decode(buffer($response->getBody()), true, flags: JSON_THROW_ON_ERROR));
    }

    /** @return array<array-key, mixed> */
    private function array(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \LogicException('Expected a JSON object or list.');
        }

        return $value;
    }
}

/** @implements Module<Ref<MessageBus>> */
final readonly class ContractReverseGeocodeModule implements Module
{
    public function __construct(private ContractReverseGeocodePort $port) {}

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

final readonly class ContractReverseGeocodePort implements
    ReverseGeocodePort,
    SearchAddressPort,
    MissingAddressCoordinatesPublisher
{
    public function __construct(private string $mode) {}

    public function locate(
        float $latitude,
        float $longitude,
        Cancellation $cancellation,
    ): ReverseGeocodeLocation {
        $cancellation->throwIfRequested();
        if ($this->mode === 'nominatim-unavailable') {
            throw new ReverseGeocodeUnavailable();
        }

        return new ReverseGeocodeLocation(
            displayName: 'Регион Альфа, Тестоград, Общая улица, 10',
            latitude: $latitude,
            longitude: $longitude,
            sourceType: 'way',
            sourceId: 770005,
            address: new ReverseGeocodeAddress(
                query: 'Регион Альфа Тестоград ул. Общая д 10',
                route: 'ул. Общая',
                houseNumber: '10',
            ),
        );
    }

    public function search(SearchAddressCriteria $criteria, Cancellation $cancellation): array
    {
        $cancellation->throwIfRequested();
        if ($this->mode === 'search-unavailable') {
            throw new SearchAddressUnavailable();
        }
        if ($this->mode === 'fallback') {
            return [];
        }

        return [new SearchAddressMatch(
            document: new AddressDocument(
                objectId: 770005,
                objectGuid: '00000000-0000-4000-8000-000000770005',
                address: 'Регион Альфа, г. Тестоград, ул. Общая, д. 10',
                hierarchy: $criteria->hierarchy,
                level: 8,
                postalCode: null,
                okato: null,
                oktmo: null,
                latitude: 55.750001,
                longitude: 37.610001,
                source: 'gar',
                sourceId: '770005',
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
    }
}
