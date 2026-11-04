<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use function Amp\ByteStream\buffer;

use Amp\Cancellation;
use Amp\Http\Server\Request;
use App\Application\DependencyInjection\ApplicationMessageBusModule;
use App\Application\MessageBus\MessageBusRegistrySource;
use App\Application\Observability\CorrelationContext;
use App\Module\AddressRegistry\Capability\AddressDocument;
use App\Module\AddressRegistry\Capability\FindAddressById\FindAddressByIdHandler;
use App\Module\AddressRegistry\Capability\FindAddressById\FindAddressByIdPort;
use App\Module\AddressRegistry\Capability\FindAddressById\FindAddressByIdUnavailable;
use App\Module\AddressRegistry\UserInterface\Http\FindAddressById\FindAddressByIdAction;
use App\Module\AddressRegistry\UserInterface\Http\FindAddressById\FindAddressByIdPresenter;
use App\Module\AddressRegistry\UserInterface\Http\FindAddressById\FindAddressByIdRequestMapper;
use App\Tests\Support\Http\TestClient;
use Fedor\MessageBus\MessageBus;
use Fedor\MessageBus\QueryBusInterface;
use League\Uri\Http;
use Psr\Log\NullLogger;
use Symfony\Component\Validator\Validation;
use Testo\Assert;
use Testo\Test;
use Thesis\Dic;
use Thesis\Dic\Module;
use Thesis\Dic\Ref;

#[Test]
final class FindAddressByIdHttpContractTest
{
    public function testItPresentsTheAcceptedSuccessEnvelope(): void
    {
        $response = $this->response(
            '{"query":"00000000-0000-4000-8000-000000770005","limit":5}',
        );
        $payload = $this->payload($response);
        $suggestions = $this->array($payload['suggestions'] ?? null);
        $suggestion = $this->array($suggestions[0] ?? null);
        $data = $this->array($suggestion['data'] ?? null);

        Assert::same($response->getStatus(), 200);
        Assert::same($payload['success'] ?? null, true);
        Assert::same($payload['count'] ?? null, 1);
        Assert::same($payload['query'] ?? null, [
            'query' => '00000000-0000-4000-8000-000000770005',
            'limit' => 5,
        ]);
        Assert::same($payload['meta'] ?? null, ['endpoint' => 'find-by-id']);
        Assert::same($data['object_id'] ?? null, 770005);
        Assert::same($data['search_backend'] ?? null, 'postgres');
    }

    public function testItPresentsStableValidationAndUnavailableErrors(): void
    {
        $invalid = $this->response('{"query":["770005"]}');
        $invalidPayload = $this->payload($invalid);
        $unavailable = $this->response('{"query":"770005"}', unavailable: true);
        $unavailablePayload = $this->payload($unavailable);

        Assert::same($invalid->getStatus(), 400);
        Assert::same($invalidPayload['error_code'] ?? null, 'invalid_query');
        Assert::same($invalidPayload['suggestions'] ?? null, []);
        Assert::same($unavailable->getStatus(), 503);
        Assert::same($unavailablePayload['error_code'] ?? null, 'search_unavailable');
        Assert::same($unavailablePayload['query'] ?? null, ['query' => '770005', 'limit' => 20]);
    }

    private function response(string $body, bool $unavailable = false): \Amp\Http\Server\Response
    {
        return Dic::run(
            new ContractFindAddressByIdModule(new ContractFindAddressByIdPort($unavailable)),
            fn(MessageBus $messageBus): \Amp\Http\Server\Response => $this
                ->action($messageBus)
                ->handle($this->request($body)),
        );
    }

    private function action(QueryBusInterface $messageBus): FindAddressByIdAction
    {
        return new FindAddressByIdAction(
            new FindAddressByIdRequestMapper(
                Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
            ),
            new FindAddressByIdPresenter(),
            $messageBus,
            10,
        );
    }

    private function request(string $body): Request
    {
        return new Request(
            new TestClient(),
            'POST',
            Http::new('http://localhost/api/addresses/find-by-id'),
            ['content-type' => 'application/json'],
            $body,
        );
    }

    /** @return array<array-key, mixed> */
    private function payload(\Amp\Http\Server\Response $response): array
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
final readonly class ContractFindAddressByIdModule implements Module
{
    public function __construct(private ContractFindAddressByIdPort $reader) {}

    /** @return Ref<MessageBus> */
    public function configure(Dic $dic): Ref
    {
        $handler = $dic
            ->object(FindAddressByIdHandler::class)
            ->arg('reader', $dic->value($this->reader))
            ->canBeScoped();

        return $dic->import(new ApplicationMessageBusModule(
            new MessageBusRegistrySource(
                classes: [FindAddressByIdHandler::class],
                services: [FindAddressByIdHandler::class => FindAddressByIdHandler::class],
                flows: MessageBusRegistrySource::empty()->flows,
            ),
            $dic->object(CorrelationContext::class),
            $dic->value(new NullLogger()),
            [FindAddressByIdHandler::class => $handler],
        ));
    }
}

final readonly class ContractFindAddressByIdPort implements FindAddressByIdPort
{
    public function __construct(private bool $unavailable = false) {}

    public function find(string $id, int $limit, Cancellation $cancellation): array
    {
        if ($this->unavailable) {
            throw new FindAddressByIdUnavailable();
        }

        return [new AddressDocument(
            objectId: 770005,
            objectGuid: '00000000-0000-4000-8000-000000770005',
            address: 'Регион Альфа, г. Тестоград, ул. Общая, д. 10',
            hierarchy: 'adm',
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
            aliasCount: 0,
        )];
    }
}
