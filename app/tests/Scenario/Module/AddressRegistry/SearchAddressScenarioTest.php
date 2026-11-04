<?php

declare(strict_types=1);

namespace App\Tests\Scenario\Module\AddressRegistry;

use Amp\Cancellation;
use Amp\DeferredCancellation;
use App\Application\DependencyInjection\ApplicationMessageBusModule;
use App\Application\MessageBus\MessageBusRegistrySource;
use App\Application\Observability\CorrelationContext;
use App\Module\AddressRegistry\Capability\AddressDocument;
use App\Module\AddressRegistry\Capability\AddressHierarchy;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddress;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressCriteria;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressHandler;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressMatch;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressPort;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressResult;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchProfile;
use Fedor\MessageBus\Execution\ExecutionOptions;
use Fedor\MessageBus\MessageBus;
use Psr\Log\NullLogger;
use Testo\Assert;
use Testo\Test;
use Thesis\Dic;
use Thesis\Dic\Module;
use Thesis\Dic\Ref;

#[Test]
final class SearchAddressScenarioTest
{
    public function testItDispatchesTypedCriteriaAndPropagatesCancellation(): void
    {
        $reader = new RecordingSearchAddressPort();
        $deferredCancellation = new DeferredCancellation();

        $result = Dic::run(
            new SearchAddressScenarioModule($reader),
            static fn(MessageBus $messageBus): SearchAddressResult => $messageBus->ask(
                new SearchAddress(
                    query: '  Тестоград   Общая ',
                    hierarchy: AddressHierarchy::Municipal,
                    profile: SearchProfile::RemediationDebug,
                    limit: 100,
                    regionCode: 77,
                ),
                new ExecutionOptions(cancellation: $deferredCancellation->getCancellation()),
            ),
        );

        Assert::notNull($reader->criteria);
        Assert::same($reader->criteria->query, 'Тестоград Общая');
        Assert::same($reader->criteria->hierarchy, AddressHierarchy::Municipal);
        Assert::same($reader->criteria->profile, SearchProfile::RemediationDebug);
        Assert::same($reader->criteria->limit, 50);
        Assert::same($reader->criteria->regionCode, 77);
        Assert::same($reader->cancellation, $deferredCancellation->getCancellation());
        Assert::same(array_map(
            static fn(SearchAddressMatch $match): int => $match->document->objectId,
            $result->matches,
        ), [770005]);
    }

    public function testEmptyQueryDoesNotReachThePort(): void
    {
        $reader = new RecordingSearchAddressPort();

        $result = Dic::run(
            new SearchAddressScenarioModule($reader),
            static fn(MessageBus $messageBus): SearchAddressResult => $messageBus->ask(new SearchAddress('  ')),
        );

        Assert::same($result->matches, []);
        Assert::null($reader->criteria);
    }
}

/** @implements Module<Ref<MessageBus>> */
final readonly class SearchAddressScenarioModule implements Module
{
    public function __construct(private RecordingSearchAddressPort $reader) {}

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

final class RecordingSearchAddressPort implements SearchAddressPort
{
    public ?SearchAddressCriteria $criteria = null;
    public ?Cancellation $cancellation = null;

    public function search(SearchAddressCriteria $criteria, Cancellation $cancellation): array
    {
        $this->criteria = $criteria;
        $this->cancellation = $cancellation;

        return [new SearchAddressMatch(
            document: new AddressDocument(
                objectId: 770005,
                objectGuid: '00000000-0000-4000-8000-000000770005',
                address: 'Регион Альфа, г. Тестоград, ул. Общая, д. 10',
                hierarchy: AddressHierarchy::Municipal,
                level: 8,
                postalCode: '770001',
                okato: null,
                oktmo: null,
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
            ),
            score: 0.9,
            aliases: [],
            matchedAlias: null,
        )];
    }
}
