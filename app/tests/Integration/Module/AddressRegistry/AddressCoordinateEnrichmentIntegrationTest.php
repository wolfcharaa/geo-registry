<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\AddressRegistry;

use Amp\Postgres\PostgresConnectionPool;
use App\Application\DependencyInjection\AddressRegistryMessageBusServices;
use App\Application\DependencyInjection\ApplicationMessageBusModule;
use App\Application\DependencyInjection\WorkerRuntimeModule;
use App\Application\MessageBus\MessageBusRegistrySource;
use App\Application\MessageBus\MessageCodecRegistryLoader;
use App\Application\Observability\CorrelationContext;
use App\Config\EnvironmentSnapshot;
use App\Infrastructure\Http\AmpHttpClientFactory;
use App\Infrastructure\OpenSearch\OpenSearchClient;
use App\Infrastructure\OpenSearch\OpenSearchClientFactory;
use App\Infrastructure\Postgres\PostgresPoolFactory;
use App\Module\AddressRegistry\Feature\CoordinateEnrichment\Capability\AddressCoordinatesResolved;
use App\Module\AddressRegistry\Feature\OpenSearch\Infrastructure\OpenSearchAddressIndexDefinition;
use App\Tests\Support\Database\IntegrationDatabase;
use Fedor\MessageBus\MessageBus;
use Fedor\MessageBus\Worker\Queue\ConsumerOptions;
use Fedor\MessageBus\Worker\Queue\QueueWorkerRunnerOptions;
use Fedor\MessageBus\Worker\Queue\QueueWorkerRunResult;
use Fedor\MessageBus\Worker\Runtime\WorkerRuntime;
use Psr\Log\NullLogger;
use RuntimeException;
use Testo\Assert;
use Testo\Test;
use Thesis\Dic;
use Thesis\Dic\Module;
use Thesis\Dic\Ref;

#[Test]
final class AddressCoordinateEnrichmentIntegrationTest
{
    public function testItKeepsDuplicateAndOptionalProjectionFailuresSafe(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $database = PostgresPoolFactory::create($dsn, 1);
        $openSearch = self::openSearch();
        $index = 'geo-registry-coordinates-' . bin2hex(random_bytes(6));

        try {
            IntegrationDatabase::resetSchema($database);
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', ['--force-yes' => true]);
            self::seedAddress($database, 770006);
            self::seedAddress($database, 770007);
            $openSearch->createIndex($index, OpenSearchAddressIndexDefinition::create());
            self::indexAddress($openSearch, $index, 770006, 'adm');
            self::indexAddress($openSearch, $index, 770006, 'mun');

            Dic::run(
                new AddressCoordinateEnrichmentModule($dsn, self::openSearchUrl(), $index),
                static function (AddressCoordinateEnrichmentHarness $harness) use ($database, $openSearch, $index): void {
                    $event = self::event(770006);
                    $first = $harness->publishAndRun($event);

                    Assert::same([$first->handled, $first->succeeded, $first->retried], [1, 1, 0]);
                    self::assertPostgresCoordinates($database, 770006, 55.750001, 37.610001);
                    self::assertOpenSearchCoordinates($openSearch, $index, 770006, 2, 55.750001, 37.610001);

                    $duplicate = $harness->publishAndRun($event);

                    Assert::same([$duplicate->handled, $duplicate->succeeded], [1, 1]);
                    self::assertPostgresCoordinates($database, 770006, 55.750001, 37.610001);

                    $openSearch->deleteIndex($index);
                    $duringOutage = $harness->publishAndRun(self::event(770007));

                    Assert::same([$duringOutage->handled, $duringOutage->succeeded, $duringOutage->retried], [1, 1, 0]);
                    self::assertPostgresCoordinates($database, 770007, 55.750001, 37.610001);

                    $openSearch->createIndex($index, OpenSearchAddressIndexDefinition::create());
                    self::indexAddress($openSearch, $index, 770007, 'adm');
                    $replay = $harness->publishAndRun(self::event(770007));

                    Assert::same([$replay->handled, $replay->succeeded], [1, 1]);
                    self::assertOpenSearchCoordinates($openSearch, $index, 770007, 1, 55.750001, 37.610001);
                },
            );
        } finally {
            if ($openSearch->concreteIndexExists($index)) {
                $openSearch->deleteIndex($index);
            }
            IntegrationDatabase::resetSchema($database);
            $database->close();
        }
    }

    private static function event(int $objectId): AddressCoordinatesResolved
    {
        return new AddressCoordinatesResolved(
            objectId: $objectId,
            lat: 55.750001,
            lon: 37.610001,
            displayName: "Регион Альфа, Тестоград, Общая улица, {$objectId}",
            osmType: 'way',
            osmId: $objectId,
            source: 'nominatim',
            regionCode: 77,
        );
    }

    private static function seedAddress(PostgresConnectionPool $postgres, int $objectId): void
    {
        foreach (['adm', 'mun'] as $hierarchy) {
            $postgres->execute(<<<'SQL'
INSERT INTO address_search (
    object_id, object_guid, address_string, hierarchy_type, level, postal_code,
    okato, oktmo, geo_lat, geo_lon, geocoded_at, address_source
) VALUES (
    :object_id, CAST(:object_guid AS uuid), :address, :hierarchy, 8, '770001',
    '77001000000', '77001001000', NULL, NULL, NULL, 'gar'
)
SQL, [
                'object_id' => $objectId,
                'object_guid' => sprintf('00000000-0000-4000-8000-%012d', $objectId),
                'address' => "Регион Альфа, Тестоград, Общая улица, {$objectId}",
                'hierarchy' => $hierarchy,
            ]);
        }
    }

    private static function indexAddress(
        OpenSearchClient $openSearch,
        string $index,
        int $objectId,
        string $hierarchy,
    ): void {
        $openSearch->indexDocument($index, "77:{$hierarchy}:{$objectId}", [
            'object_id' => $objectId,
            'object_guid' => sprintf('00000000-0000-4000-8000-%012d', $objectId),
            'address' => "Регион Альфа, Тестоград, Общая улица, {$objectId}",
            'hierarchy_type' => $hierarchy,
            'region_code' => 77,
            'level' => 8,
            'geo_lat' => null,
            'geo_lon' => null,
            'source' => 'gar',
            'alias_count' => 0,
            'aliases' => [],
        ]);
        $openSearch->refreshIndex($index);
    }

    private static function assertPostgresCoordinates(
        PostgresConnectionPool $postgres,
        int $objectId,
        float $latitude,
        float $longitude,
    ): void {
        $result = $postgres->execute(
            'SELECT geo_lat, geo_lon, geocode_source, geocode_osm_id
             FROM address_search WHERE object_id = :object_id ORDER BY hierarchy_type',
            ['object_id' => $objectId],
        );
        $rows = [];
        while (($row = $result->fetchRow()) !== null) {
            $rows[] = $row;
        }

        Assert::same(count($rows), 2);
        foreach ($rows as $row) {
            Assert::same((float) ($row['geo_lat'] ?? 0), $latitude);
            Assert::same((float) ($row['geo_lon'] ?? 0), $longitude);
            Assert::same($row['geocode_source'] ?? null, 'nominatim');
            Assert::same((int) ($row['geocode_osm_id'] ?? 0), $objectId);
        }
    }

    private static function assertOpenSearchCoordinates(
        OpenSearchClient $openSearch,
        string $index,
        int $objectId,
        int $expectedDocuments,
        float $latitude,
        float $longitude,
    ): void {
        $response = $openSearch->search($index, ['term' => ['object_id' => $objectId]], 10);
        $hitContainer = $response['hits'] ?? null;
        if (!is_array($hitContainer)) {
            throw new RuntimeException('OpenSearch coordinate query returned no hit container.');
        }
        $hits = $hitContainer['hits'] ?? null;
        if (!is_array($hits)) {
            throw new RuntimeException('OpenSearch coordinate query returned invalid hits.');
        }

        Assert::same(count($hits), $expectedDocuments);
        foreach ($hits as $hit) {
            if (!is_array($hit) || !is_array($hit['_source'] ?? null)) {
                throw new RuntimeException('OpenSearch coordinate hit has no source.');
            }
            Assert::same($hit['_source']['geo_lat'] ?? null, $latitude);
            Assert::same($hit['_source']['geo_lon'] ?? null, $longitude);
        }
    }

    private static function openSearch(): OpenSearchClient
    {
        return (new OpenSearchClientFactory(AmpHttpClientFactory::create(10)))->create(
            self::openSearchUrl(),
            'geo-registry-integration-test/0.1',
        );
    }

    private static function openSearchUrl(): string
    {
        $url = getenv('INTEGRATION_OPENSEARCH_URL');

        return is_string($url) && $url !== '' ? $url : 'http://127.0.0.1:59200';
    }
}

/** @implements Module<Ref<AddressCoordinateEnrichmentHarness>> */
final readonly class AddressCoordinateEnrichmentModule implements Module
{
    public function __construct(
        private string $dsn,
        private string $openSearchUrl,
        private string $openSearchIndex,
    ) {}

    /** @return Ref<AddressCoordinateEnrichmentHarness> */
    public function configure(Dic $dic): Ref
    {
        $source = MessageBusRegistrySource::fromFile(dirname(__DIR__, 4) . '/config/message_bus_registry.php');
        $codecs = MessageCodecRegistryLoader::fromFile(dirname(__DIR__, 4) . '/config/message_bus_codecs.php');
        $postgres = $dic
            ->object(
                PostgresConnectionPool::class,
                fn(): PostgresConnectionPool => PostgresPoolFactory::create($this->dsn, 2),
            )
            ->disposer(static fn(PostgresConnectionPool $pool) => $pool->close());
        $logger = $dic->object(NullLogger::class);
        $bus = $dic->import(new ApplicationMessageBusModule(
            $source,
            $dic->object(CorrelationContext::class),
            $logger,
            AddressRegistryMessageBusServices::configure($dic, $source, $postgres, logger: $logger),
            postgres: $postgres,
            messageCodecs: $codecs,
        ));
        $runtime = $dic->import(new WorkerRuntimeModule(new EnvironmentSnapshot([
            'APP_USER_AGENT' => 'geo-registry-integration-test/0.1',
            'DATABASE_DSN' => $this->dsn,
            'DATABASE_POOL_SIZE' => '2',
            'LOG_FORMAT' => 'json',
            'OPENSEARCH_COORDINATE_UPDATES_ENABLED' => '1',
            'OPENSEARCH_INDEX' => $this->openSearchIndex,
            'OPENSEARCH_TIMEOUT_SECONDS' => '10',
            'OPENSEARCH_URL' => $this->openSearchUrl,
        ]), $source, $codecs));

        return $dic->object(AddressCoordinateEnrichmentHarness::class)->args([
            'bus' => $bus,
            'runtime' => $runtime,
        ]);
    }
}

final class AddressCoordinateEnrichmentHarness
{
    private int $workerSequence = 0;

    public function __construct(
        private readonly MessageBus $bus,
        private readonly WorkerRuntime $runtime,
    ) {}

    public function publishAndRun(AddressCoordinatesResolved $event): QueueWorkerRunResult
    {
        $this->bus->publish($event);
        $workerName = 'coordinate-worker-' . ++$this->workerSequence;

        return $this->runtime->runner->run(
            new ConsumerOptions(
                transport: 'postgres',
                queue: 'runtime',
                timeoutSeconds: 0,
                workerId: $workerName,
                flows: ['runtime_async'],
            ),
            new QueueWorkerRunnerOptions(
                maxMessages: 1,
                stopWhenEmpty: true,
                sleepWhenIdleMilliseconds: 1,
                workerName: $workerName,
                workerGroup: 'coordinate-enrichment-test',
                heartbeatIntervalMilliseconds: 10,
                cancellationPollIntervalMilliseconds: 10,
                controlPollIntervalMilliseconds: 10,
            ),
        );
    }
}
