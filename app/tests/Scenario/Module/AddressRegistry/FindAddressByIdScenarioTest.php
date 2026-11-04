<?php

declare(strict_types=1);

namespace App\Tests\Scenario\Module\AddressRegistry;

use Amp\Cancellation;
use Amp\DeferredCancellation;
use App\Application\DependencyInjection\ApplicationMessageBusModule;
use App\Application\MessageBus\MessageBusRegistrySource;
use App\Application\Observability\CorrelationContext;
use App\Module\AddressRegistry\Capability\AddressDocument;
use App\Module\AddressRegistry\Capability\FindAddressById\FindAddressById;
use App\Module\AddressRegistry\Capability\FindAddressById\FindAddressByIdHandler;
use App\Module\AddressRegistry\Capability\FindAddressById\FindAddressByIdPort;
use App\Module\AddressRegistry\Capability\FindAddressById\FindAddressByIdResult;
use Fedor\MessageBus\Execution\ExecutionOptions;
use Fedor\MessageBus\MessageBus;
use Psr\Log\NullLogger;
use Testo\Assert;
use Testo\Test;
use Thesis\Dic;
use Thesis\Dic\Module;
use Thesis\Dic\Ref;

#[Test]
final class FindAddressByIdScenarioTest
{
    public function testItDispatchesNormalizedQueryAndPropagatesCancellation(): void
    {
        $reader = new RecordingFindAddressByIdPort();
        $deferredCancellation = new DeferredCancellation();

        $result = Dic::run(
            new FindAddressByIdScenarioModule($reader),
            static fn(MessageBus $messageBus): FindAddressByIdResult => $messageBus->ask(
                new FindAddressById('  770005  ', 100),
                new ExecutionOptions(cancellation: $deferredCancellation->getCancellation()),
            ),
        );

        Assert::same($reader->id, '770005');
        Assert::same($reader->limit, 20);
        Assert::same($reader->cancellation, $deferredCancellation->getCancellation());
        Assert::same(array_map(
            static fn(AddressDocument $document): int => $document->objectId,
            $result->documents,
        ), [770005]);
    }

    public function testEmptyIdentifierDoesNotReachThePort(): void
    {
        $reader = new RecordingFindAddressByIdPort();

        $result = Dic::run(
            new FindAddressByIdScenarioModule($reader),
            static fn(MessageBus $messageBus): FindAddressByIdResult => $messageBus->ask(new FindAddressById('  ')),
        );

        Assert::same($result->documents, []);
        Assert::same($reader->id, null);
    }
}

/** @implements Module<Ref<MessageBus>> */
final readonly class FindAddressByIdScenarioModule implements Module
{
    public function __construct(private RecordingFindAddressByIdPort $reader) {}

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

final class RecordingFindAddressByIdPort implements FindAddressByIdPort
{
    public ?string $id = null;
    public ?int $limit = null;
    public ?Cancellation $cancellation = null;

    public function find(string $id, int $limit, Cancellation $cancellation): array
    {
        $this->id = $id;
        $this->limit = $limit;
        $this->cancellation = $cancellation;

        return [new AddressDocument(
            objectId: 770005,
            objectGuid: '00000000-0000-4000-8000-000000770005',
            address: 'Регион Альфа, г. Тестоград, ул. Общая, д. 10',
            hierarchy: 'adm',
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
        )];
    }
}
