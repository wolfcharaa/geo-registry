<?php

declare(strict_types=1);

namespace App\Tests\Scenario\Module\AddressRegistry;

use Amp\Cancellation;
use App\Application\DependencyInjection\ApplicationMessageBusModule;
use App\Application\MessageBus\MessageBusRegistrySource;
use App\Application\Observability\CorrelationContext;
use App\Module\AddressRegistry\Capability\AddressDocument;
use App\Module\AddressRegistry\Capability\AddressHierarchy;
use App\Module\AddressRegistry\Capability\ProgressiveAddressSuggestion;
use App\Module\AddressRegistry\Capability\ResolveSuggestionContext\ResolveSuggestionContextHandler;
use App\Module\AddressRegistry\Capability\ResolveSuggestionContext\ResolveSuggestionContextPort;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressCriteria;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressHandler;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressMatch;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressPort;
use App\Module\AddressRegistry\Capability\SuggestAddress\SuggestAddress;
use App\Module\AddressRegistry\Capability\SuggestAddress\SuggestAddressHandler;
use App\Module\AddressRegistry\Capability\SuggestAddress\SuggestAddressResult;
use App\Module\AddressRegistry\Capability\SuggestAdministrativeArea\SuggestAdministrativeAreaHandler;
use App\Module\AddressRegistry\Capability\SuggestAdministrativeArea\SuggestAdministrativeAreaPort;
use App\Module\AddressRegistry\Capability\SuggestionContext;
use App\Module\AddressRegistry\Capability\SuggestionContextNotFound;
use App\Module\AddressRegistry\Capability\SuggestionCriteria;
use App\Module\AddressRegistry\Capability\SuggestionDistrict;
use App\Module\AddressRegistry\Capability\SuggestionStage;
use App\Module\AddressRegistry\Capability\SuggestThoroughfare\SuggestThoroughfareHandler;
use App\Module\AddressRegistry\Capability\SuggestThoroughfare\SuggestThoroughfarePort;
use Fedor\MessageBus\MessageBus;
use Psr\Log\NullLogger;
use Testo\Assert;
use Testo\Test;
use Thesis\Dic;
use Thesis\Dic\Module;
use Thesis\Dic\Ref;

#[Test]
final class SuggestAddressScenarioTest
{
    public function testItOrchestratesCityDistrictAndStreetThroughNestedQueries(): void
    {
        $reader = new RecordingSuggestionPort();

        $results = Dic::run(
            new SuggestAddressScenarioModule($reader),
            static fn(MessageBus $messageBus): array => [
                $messageBus->ask(new SuggestAddress(
                    suggest: 'Тестоград',
                    stage: SuggestionStage::AdministrativeArea,
                    hierarchy: AddressHierarchy::Municipal,
                )),
                $messageBus->ask(new SuggestAddress(
                    suggest: 'Центральный',
                    stage: SuggestionStage::AdministrativeArea,
                    hierarchy: AddressHierarchy::Municipal,
                    parentId: 770001,
                )),
                $messageBus->ask(new SuggestAddress(
                    suggest: 'Общая',
                    stage: SuggestionStage::Thoroughfare,
                    hierarchy: AddressHierarchy::Municipal,
                    parentId: 770002,
                )),
            ],
        );

        Assert::same(self::ids($results[0]), [770003]);
        Assert::same(self::ids($results[1]), [770002]);
        Assert::same(self::ids($results[2]), [770004]);
        Assert::same($reader->resolvedParentIds, [770001, 770002]);
        Assert::same(array_map(
            static fn(SuggestionCriteria $criteria): ?int => $criteria->context?->regionCode,
            $reader->administrativeCriteria,
        ), [null, 77]);
        $thoroughfareCriteria = $reader->thoroughfareCriteria[0] ?? null;
        Assert::notNull($thoroughfareCriteria);
        $thoroughfareContext = $thoroughfareCriteria->context;
        Assert::notNull($thoroughfareContext);
        Assert::same($thoroughfareContext->parentId, 770002);
        Assert::same($thoroughfareContext->regionCode, 77);
    }

    public function testItReturnsAnEmptyResultWithoutBypassingTheNestedCapability(): void
    {
        $reader = new RecordingSuggestionPort();

        $result = Dic::run(
            new SuggestAddressScenarioModule($reader),
            static fn(MessageBus $messageBus): SuggestAddressResult => $messageBus->ask(new SuggestAddress(
                suggest: 'Неизвестный',
                stage: SuggestionStage::AdministrativeArea,
                hierarchy: AddressHierarchy::Administrative,
            )),
        );

        Assert::same($result->progressiveSuggestions, []);
        Assert::same(count($reader->administrativeCriteria), 1);
    }

    public function testItRejectsAnUnknownParentBeforeTheStageReaderRuns(): void
    {
        $reader = new RecordingSuggestionPort();
        $error = null;

        try {
            Dic::run(
                new SuggestAddressScenarioModule($reader),
                static fn(MessageBus $messageBus): SuggestAddressResult => $messageBus->ask(new SuggestAddress(
                    suggest: 'Общая',
                    stage: SuggestionStage::Thoroughfare,
                    hierarchy: AddressHierarchy::Municipal,
                    parentId: 999999999,
                )),
            );
        } catch (SuggestionContextNotFound $exception) {
            $error = $exception;
        }

        Assert::notNull($error);
        Assert::same($reader->resolvedParentIds, [999999999]);
        Assert::same($reader->thoroughfareCriteria, []);
    }

    public function testSearchStageReusesThePublicRegionalSearchCapability(): void
    {
        $reader = new RecordingSuggestionPort();

        $result = Dic::run(
            new SuggestAddressScenarioModule($reader),
            static fn(MessageBus $messageBus): SuggestAddressResult => $messageBus->ask(new SuggestAddress(
                suggest: '10',
                stage: SuggestionStage::Search,
                hierarchy: AddressHierarchy::Municipal,
                exact: 'Тестоград Общая',
                parentId: 770004,
            )),
        );

        Assert::same(array_map(
            static fn(SearchAddressMatch $match): int => $match->document->objectId,
            $result->searchMatches,
        ), [770005]);
        Assert::notNull($reader->searchCriteria);
        Assert::same($reader->searchCriteria->query, 'Тестоград Общая 10');
        Assert::same($reader->searchCriteria->regionCode, 77);
    }

    /** @return list<int> */
    private static function ids(SuggestAddressResult $result): array
    {
        return array_map(
            static fn(ProgressiveAddressSuggestion $suggestion): int => $suggestion->objectId,
            $result->progressiveSuggestions,
        );
    }
}

/** @implements Module<Ref<MessageBus>> */
final readonly class SuggestAddressScenarioModule implements Module
{
    public function __construct(private RecordingSuggestionPort $reader) {}

    /** @return Ref<MessageBus> */
    public function configure(Dic $dic): Ref
    {
        $reader = $dic->value($this->reader);
        $services = [
            ResolveSuggestionContextHandler::class => $dic
                ->object(ResolveSuggestionContextHandler::class)
                ->arg('reader', $reader)
                ->canBeScoped(),
            SearchAddressHandler::class => $dic
                ->object(SearchAddressHandler::class)
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

final class RecordingSuggestionPort implements
    ResolveSuggestionContextPort,
    SearchAddressPort,
    SuggestAdministrativeAreaPort,
    SuggestThoroughfarePort
{
    /** @var list<int> */
    public array $resolvedParentIds = [];

    /** @var list<SuggestionCriteria> */
    public array $administrativeCriteria = [];

    /** @var list<SuggestionCriteria> */
    public array $thoroughfareCriteria = [];

    public ?SearchAddressCriteria $searchCriteria = null;

    public function resolve(
        int $parentId,
        AddressHierarchy $hierarchy,
        Cancellation $cancellation,
    ): ?SuggestionContext {
        $cancellation->throwIfRequested();
        $this->resolvedParentIds[] = $parentId;
        if (!in_array($parentId, [770001, 770002, 770004], true)) {
            return null;
        }

        return new SuggestionContext(
            parentId: $parentId,
            regionCode: 77,
            hierarchy: $hierarchy,
            level: match ($parentId) {
                770001 => 1,
                770002 => 2,
                default => 7,
            },
            district: $parentId === 770001 ? null : new SuggestionDistrict(770002, 'Центральный'),
        );
    }

    public function search(SearchAddressCriteria $criteria, Cancellation $cancellation): array
    {
        $cancellation->throwIfRequested();
        $this->searchCriteria = $criteria;

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
                dataMosDistrict: 'Центральный',
                cadastralNumbers: [],
                landCadastralNumbers: [],
                aliasCount: 0,
            ),
            score: 1.0,
            aliases: [],
            matchedAlias: null,
        )];
    }

    public function suggestAdministrativeAreas(
        SuggestionCriteria $criteria,
        Cancellation $cancellation,
    ): array {
        $cancellation->throwIfRequested();
        $this->administrativeCriteria[] = $criteria;

        return match ($criteria->query) {
            'Тестоград' => [$this->suggestion(770003, 'Тестоград', 'г.', 5, $criteria->hierarchy)],
            'Центральный' => [$this->suggestion(770002, 'Центральный', 'р-н', 2, $criteria->hierarchy)],
            default => [],
        };
    }

    public function suggestThoroughfares(
        SuggestionCriteria $criteria,
        Cancellation $cancellation,
    ): array {
        $cancellation->throwIfRequested();
        $this->thoroughfareCriteria[] = $criteria;

        return $criteria->query === 'Общая'
            ? [$this->suggestion(770004, 'Общая', 'ул.', 7, $criteria->hierarchy)]
            : [];
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
            district: $level >= 5 ? new SuggestionDistrict(770002, 'Центральный') : null,
        );
    }
}
