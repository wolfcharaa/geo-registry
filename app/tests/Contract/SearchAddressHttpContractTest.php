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
use App\Module\AddressRegistry\Capability\AddressAlias;
use App\Module\AddressRegistry\Capability\AddressDocument;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressCriteria;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressHandler;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressMatch;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressPort;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressUnavailable;
use App\Module\AddressRegistry\UserInterface\Http\AddressSuggestionPresenter;
use App\Module\AddressRegistry\UserInterface\Http\SearchAddress\SearchAddressAction;
use App\Module\AddressRegistry\UserInterface\Http\SearchAddress\SearchAddressPresenter;
use App\Module\AddressRegistry\UserInterface\Http\SearchAddress\SearchAddressRequestMapper;
use App\Tests\Support\Http\TestClient;
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
final class SearchAddressHttpContractTest
{
    public function testItPresentsTheAcceptedRegionalSearchEnvelope(): void
    {
        $response = $this->response([
            'q' => 'Тестоград Общая',
            'hier' => 'mun',
            'limit' => 5,
            'region_code' => 77,
        ]);
        $payload = $this->payload($response);
        $suggestions = $this->array($payload['suggestions'] ?? null);
        $suggestion = $this->array($suggestions[0] ?? null);
        $data = $this->array($suggestion['data'] ?? null);
        $aliases = $this->array($data['aliases'] ?? null);

        Assert::same($response->getStatus(), 200);
        Assert::same($payload['success'] ?? null, true);
        Assert::same($payload['count'] ?? null, 1);
        Assert::same($payload['query'] ?? null, [
            'q' => 'Тестоград Общая',
            'hierarchy' => 'mun',
            'limit' => 5,
            'profile' => 'search',
            'region_code' => 77,
        ]);
        Assert::same($payload['meta'] ?? null, ['endpoint' => 'search']);
        Assert::same($data['object_id'] ?? null, 770005);
        Assert::same($data['hierarchy_type'] ?? null, 'mun');
        Assert::same($data['matched_alias'] ?? null, 'Тестоград, Общая улица, дом 10');
        Assert::same($this->array($aliases[0] ?? null)['source'] ?? null, 'osm');
    }

    public function testItPresentsStableMissingQueryAndUnavailableErrors(): void
    {
        $missing = $this->response([]);
        $missingPayload = $this->payload($missing);
        $unavailable = $this->response(['q' => 'Тестоград'], unavailable: true);
        $unavailablePayload = $this->payload($unavailable);

        Assert::same($missing->getStatus(), 400);
        Assert::same($missingPayload['error_code'] ?? null, 'missing_query');
        Assert::same($missingPayload['query'] ?? null, [
            'q' => '',
            'hierarchy' => 'adm',
            'limit' => 10,
            'profile' => 'search',
        ]);
        Assert::same($unavailable->getStatus(), 503);
        Assert::same($unavailablePayload['error_code'] ?? null, 'search_unavailable');
        Assert::same($unavailablePayload['suggestions'] ?? null, []);
    }

    /** @param array<string, int|string> $parameters */
    private function response(array $parameters, bool $unavailable = false): Response
    {
        return Dic::run(
            new ContractSearchAddressModule(new ContractSearchAddressPort($unavailable)),
            fn(MessageBus $messageBus): Response => $this
                ->action($messageBus)
                ->handle($this->request($parameters)),
        );
    }

    private function action(MessageBus $messageBus): SearchAddressAction
    {
        return new SearchAddressAction(
            new SearchAddressRequestMapper(
                Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
            ),
            new SearchAddressPresenter(new AddressSuggestionPresenter()),
            $messageBus,
            10,
        );
    }

    /** @param array<string, int|string> $parameters */
    private function request(array $parameters): Request
    {
        $query = http_build_query($parameters);

        return new Request(
            new TestClient(),
            'GET',
            Http::new('http://localhost/api/addresses/search' . ($query === '' ? '' : '?' . $query)),
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
final readonly class ContractSearchAddressModule implements Module
{
    public function __construct(private ContractSearchAddressPort $reader) {}

    /** @return Ref<MessageBus> */
    public function configure(Dic $dic): Ref
    {
        $handler = $dic
            ->object(SearchAddressHandler::class)
            ->arg('reader', $dic->value($this->reader))
            ->canBeScoped();

        return $dic->import(new ApplicationMessageBusModule(
            new MessageBusRegistrySource(
                classes: [SearchAddressHandler::class],
                services: [SearchAddressHandler::class => SearchAddressHandler::class],
                flows: MessageBusRegistrySource::empty()->flows,
            ),
            $dic->object(CorrelationContext::class),
            $dic->value(new NullLogger()),
            [SearchAddressHandler::class => $handler],
        ));
    }
}

final readonly class ContractSearchAddressPort implements SearchAddressPort
{
    public function __construct(private bool $unavailable = false) {}

    public function search(SearchAddressCriteria $criteria, Cancellation $cancellation): array
    {
        $cancellation->throwIfRequested();
        if ($this->unavailable) {
            throw new SearchAddressUnavailable();
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
                latitude: 55.750001,
                longitude: 37.610001,
                source: 'gar',
                sourceId: null,
                dataMosGlobalId: null,
                dataMosUnom: null,
                dataMosNreg: null,
                dataMosAdministrativeArea: null,
                dataMosDistrict: null,
                cadastralNumbers: [],
                landCadastralNumbers: [],
                aliasCount: 1,
            ),
            score: 0.9,
            aliases: [new AddressAlias(
                value: 'Тестоград, Общая улица, дом 10',
                kind: 'alternative_address',
                source: 'osm',
                sourceObjectType: 'way',
                sourceObjectId: 770005,
                rank: 2,
            )],
            matchedAlias: 'Тестоград, Общая улица, дом 10',
        )];
    }
}
