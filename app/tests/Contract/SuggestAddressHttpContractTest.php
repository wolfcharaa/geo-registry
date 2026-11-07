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
use App\Module\AddressRegistry\Capability\AddressHierarchy;
use App\Module\AddressRegistry\Capability\ProgressiveAddressSuggestion;
use App\Module\AddressRegistry\Capability\ResolveSuggestionContext\ResolveSuggestionContextHandler;
use App\Module\AddressRegistry\Capability\ResolveSuggestionContext\ResolveSuggestionContextPort;
use App\Module\AddressRegistry\Capability\SuggestAddress\SuggestAddressHandler;
use App\Module\AddressRegistry\Capability\SuggestAddressUnavailable;
use App\Module\AddressRegistry\Capability\SuggestAdministrativeArea\SuggestAdministrativeAreaHandler;
use App\Module\AddressRegistry\Capability\SuggestAdministrativeArea\SuggestAdministrativeAreaPort;
use App\Module\AddressRegistry\Capability\SuggestionContext;
use App\Module\AddressRegistry\Capability\SuggestionCriteria;
use App\Module\AddressRegistry\Capability\SuggestionDistrict;
use App\Module\AddressRegistry\Capability\SuggestThoroughfare\SuggestThoroughfareHandler;
use App\Module\AddressRegistry\Capability\SuggestThoroughfare\SuggestThoroughfarePort;
use App\Module\AddressRegistry\UserInterface\Http\AddressSuggestionPresenter;
use App\Module\AddressRegistry\UserInterface\Http\SuggestAddress\SuggestAddressAction;
use App\Module\AddressRegistry\UserInterface\Http\SuggestAddress\SuggestAddressPresenter;
use App\Module\AddressRegistry\UserInterface\Http\SuggestAddress\SuggestAddressRequestMapper;
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
final class SuggestAddressHttpContractTest
{
    public function testItPresentsTheProgressiveSuggestionEnvelope(): void
    {
        $response = $this->response(<<<'JSON'
{
  "suggest": "Тестоград",
  "stage": "administrative_area",
  "hierarchy": "mun",
  "count": 5
}
JSON);
        $payload = $this->payload($response);
        $suggestions = $this->array($payload['suggestions'] ?? null);
        $suggestion = $this->array($suggestions[0] ?? null);
        $data = $this->array($suggestion['data'] ?? null);
        $context = $this->array($data['context'] ?? null);

        Assert::same($response->getStatus(), 200);
        Assert::same($payload['success'] ?? null, true);
        Assert::same($payload['count'] ?? null, 1);
        Assert::same($payload['query'] ?? null, [
            'exact' => '',
            'suggest' => 'Тестоград',
            'stage' => 'administrative_area',
            'hierarchy' => 'mun',
            'parent_id' => null,
            'count' => 5,
        ]);
        Assert::same($payload['meta'] ?? null, [
            'endpoint' => 'suggest',
            'contract_version' => 2,
            'stage' => 'administrative_area',
        ]);
        Assert::same($suggestion['value'] ?? null, 'г. Тестоград');
        Assert::same($data['object_id'] ?? null, 770003);
        Assert::same($data['next_stage'] ?? null, 'thoroughfare');
        Assert::same($data['ready_for_search'] ?? null, false);
        Assert::same($context['parent_id'] ?? null, 770003);
    }

    public function testItKeepsTheFederalCityTransitionCompatible(): void
    {
        $response = $this->response(<<<'JSON'
{
  "suggest": "Москва",
  "stage": "administrative_area",
  "hierarchy": "mun",
  "count": 5
}
JSON);
        $payload = $this->payload($response);
        $suggestions = $this->array($payload['suggestions'] ?? null);
        $suggestion = $this->array($suggestions[0] ?? null);
        $data = $this->array($suggestion['data'] ?? null);

        Assert::same($response->getStatus(), 200);
        Assert::same($suggestion['value'] ?? null, 'г. Москва');
        Assert::same($data['next_stage'] ?? null, 'thoroughfare');
        Assert::same($data['ready_for_search'] ?? null, false);
    }

    public function testItPresentsStableValidationContextAndStorageErrors(): void
    {
        $invalidStage = $this->response(
            '{"suggest":"Общая","stage":"unknown","hierarchy":"mun"}',
        );
        $unknownParent = $this->response(<<<'JSON'
{"suggest":"Общая","stage":"thoroughfare","hierarchy":"mun","context":{"parent_id":999999999}}
JSON);
        $unavailable = $this->response(<<<'JSON'
{"suggest":"Тестоград","stage":"administrative_area","hierarchy":"mun"}
JSON, unavailable: true);

        Assert::same($invalidStage->getStatus(), 400);
        Assert::same($this->payload($invalidStage)['error_code'] ?? null, 'invalid_stage');
        Assert::same($unknownParent->getStatus(), 422);
        Assert::same($this->payload($unknownParent)['error_code'] ?? null, 'suggestion_context_not_found');
        Assert::same($unavailable->getStatus(), 503);
        Assert::same($this->payload($unavailable)['error_code'] ?? null, 'suggest_unavailable');
    }

    private function response(string $body, bool $unavailable = false): Response
    {
        return Dic::run(
            new ContractSuggestAddressModule(new ContractSuggestionPort($unavailable)),
            fn(MessageBus $messageBus): Response => $this->action($messageBus)->handle($this->request($body)),
        );
    }

    private function action(MessageBus $messageBus): SuggestAddressAction
    {
        return new SuggestAddressAction(
            new SuggestAddressRequestMapper(
                Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
            ),
            new SuggestAddressPresenter(new AddressSuggestionPresenter()),
            $messageBus,
            10,
        );
    }

    private function request(string $body): Request
    {
        return new Request(
            new TestClient(),
            'POST',
            Http::new('http://localhost/api/addresses/suggest'),
            ['content-type' => 'application/json'],
            $body,
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
final readonly class ContractSuggestAddressModule implements Module
{
    public function __construct(private ContractSuggestionPort $reader) {}

    /** @return Ref<MessageBus> */
    public function configure(Dic $dic): Ref
    {
        $reader = $dic->value($this->reader);
        $services = [
            ResolveSuggestionContextHandler::class => $dic
                ->object(ResolveSuggestionContextHandler::class)
                ->arg('reader', $reader)
                ->canBeScoped(),
            SuggestAddressHandler::class => $dic
                ->object(SuggestAddressHandler::class)
                ->canBeScoped(),
            SuggestAdministrativeAreaHandler::class => $dic
                ->object(SuggestAdministrativeAreaHandler::class)
                ->arg('reader', $reader)
                ->canBeScoped(),
            SuggestThoroughfareHandler::class => $dic
                ->object(SuggestThoroughfareHandler::class)
                ->arg('reader', $reader)
                ->canBeScoped(),
        ];
        $classes = array_keys($services);
        $serviceClasses = [];
        foreach ($classes as $class) {
            $serviceClasses[$class] = $class;
        }

        return $dic->import(new ApplicationMessageBusModule(
            new MessageBusRegistrySource(
                classes: $classes,
                services: $serviceClasses,
                flows: MessageBusRegistrySource::empty()->flows,
            ),
            $dic->object(CorrelationContext::class),
            $dic->value(new NullLogger()),
            $services,
        ));
    }
}

final readonly class ContractSuggestionPort implements
    ResolveSuggestionContextPort,
    SuggestAdministrativeAreaPort,
    SuggestThoroughfarePort
{
    public function __construct(private bool $unavailable = false) {}

    public function resolve(
        int $parentId,
        AddressHierarchy $hierarchy,
        Cancellation $cancellation,
    ): ?SuggestionContext {
        $this->guard($cancellation);
        if ($parentId !== 770001 && $parentId !== 770002) {
            return null;
        }

        return new SuggestionContext(
            parentId: $parentId,
            regionCode: 77,
            hierarchy: $hierarchy,
            level: $parentId === 770001 ? 1 : 2,
            district: $parentId === 770002 ? new SuggestionDistrict(770002, 'Центральный') : null,
        );
    }

    public function suggestAdministrativeAreas(
        SuggestionCriteria $criteria,
        Cancellation $cancellation,
    ): array {
        $this->guard($cancellation);

        if ($criteria->query === 'Москва') {
            return [$this->suggestion(1405113, 'Москва', 'г.', 1, $criteria->hierarchy)];
        }

        return [$this->suggestion(770003, 'Тестоград', 'г.', 5, $criteria->hierarchy)];
    }

    public function suggestThoroughfares(
        SuggestionCriteria $criteria,
        Cancellation $cancellation,
    ): array {
        $this->guard($cancellation);

        return [$this->suggestion(770004, 'Общая', 'ул.', 8, $criteria->hierarchy)];
    }

    private function guard(Cancellation $cancellation): void
    {
        $cancellation->throwIfRequested();
        if ($this->unavailable) {
            throw new SuggestAddressUnavailable();
        }
    }

    private function suggestion(
        int $objectId,
        string $name,
        string $typeName,
        int $level,
        AddressHierarchy $hierarchy,
    ): ProgressiveAddressSuggestion {
        return new ProgressiveAddressSuggestion(
            objectId: $objectId,
            objectGuid: sprintf('00000000-0000-4000-8000-%012d', $objectId),
            name: $name,
            typeName: $typeName,
            hierarchy: $hierarchy,
            level: $level,
            regionCode: 77,
            district: new SuggestionDistrict(770002, 'Центральный'),
        );
    }
}
