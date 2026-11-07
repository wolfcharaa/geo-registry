<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use App\Config\AddressSourceConfig;
use App\Config\ConsoleConfig;
use App\Config\EnvironmentSnapshot;
use App\Config\HttpServerConfig;
use App\Config\LogFormat;
use App\Config\LoggingConfig;
use App\Config\NominatimConfig;
use App\Config\OpenSearchConfig;
use App\Config\PostgresConfig;
use InvalidArgumentException;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Test;

#[Test]
final class RuntimeConfigTest
{
    public function testItBuildsIndependentTypedSettingsFromTheSameSnapshot(): void
    {
        $environment = new EnvironmentSnapshot([
            'APP_NAME' => 'geo-registry-test',
            'APP_VERSION' => 'test',
            'APP_LISTEN' => '0.0.0.0:9000',
            'APP_USER_AGENT' => 'geo-registry/test',
            'APP_HTTP_WORKERS' => '3',
            'APP_HTTP_SHUTDOWN_TIMEOUT_SECONDS' => '25',
            'APP_HTTP_WORKER_MAX_REQUESTS' => '12000',
            'APP_HTTP_WORKER_MAX_MEMORY_MB' => '384',
            'APP_HTTP_WORKER_RESTART_BURST' => '8',
            'APP_HTTP_WORKER_RESTART_WINDOW_SECONDS' => '45',
            'APP_HTTP_WORKER_RESTART_BACKOFF_INITIAL_SECONDS' => '0.75',
            'APP_HTTP_WORKER_RESTART_BACKOFF_MAX_SECONDS' => '20',
            'APP_HTTP_CONNECTION_LIMIT' => '700',
            'APP_HTTP_CONNECTIONS_PER_IP_LIMIT' => '0',
            'APP_HTTP_CONCURRENCY_LIMIT' => '350',
            'APP_HTTP_BACKLOG' => '900',
            'APP_HTTP_COMPRESSION_ENABLED' => 'false',
            'HTTP_TIMEOUT_SECONDS' => '3.5',
            'LOG_FORMAT' => 'console',
            'DATAMOS_ADDRESS_REGISTRY_SOURCE' => '/data/datamos/registry.bson.gz',
            'DATAMOS_API_KEY' => 'test-key',
            'OSM_ADDRESS_ALIASES_SOURCE' => '/data/osm/aliases.jsonl',
            'MOSCOW_REGION_CODE' => '77',
            'DATABASE_DSN' => 'host=postgres db=geo',
            'DATABASE_POOL_SIZE' => '7',
            'NOMINATIM_URL' => 'https://nominatim.example',
            'NOMINATIM_LANGUAGE' => 'ru-RU',
            'NOMINATIM_ENABLED' => 'true',
            'OPENSEARCH_URL' => 'https://search.example:9200',
            'OPENSEARCH_INDEX' => 'addresses',
            'OPENSEARCH_ENABLED' => 'true',
            'OPENSEARCH_SEARCH_ENABLED' => 'true',
            'OPENSEARCH_COORDINATE_UPDATES_ENABLED' => 'true',
            'OPENSEARCH_TIMEOUT_SECONDS' => '90',
            'OPENSEARCH_NUMBER_OF_SHARDS' => '3',
            'OPENSEARCH_NUMBER_OF_REPLICAS' => '1',
            'OPENSEARCH_BULK_NUMBER_OF_REPLICAS' => '0',
            'OPENSEARCH_REFRESH_INTERVAL' => '2s',
            'OPENSEARCH_BULK_REFRESH_INTERVAL' => '-1',
            'OPENSEARCH_REINDEX_MEMORY_LIMIT' => '1G',
        ]);

        $http = HttpServerConfig::fromEnvironment($environment);
        $addressSources = AddressSourceConfig::fromEnvironment($environment);
        $logging = LoggingConfig::fromEnvironment($environment);
        $console = ConsoleConfig::fromEnvironment($environment);
        $postgres = PostgresConfig::fromEnvironment($environment);
        $nominatim = NominatimConfig::fromEnvironment($environment);
        $openSearch = OpenSearchConfig::fromEnvironment($environment);

        Assert::same($console->name, 'geo-registry-test');
        Assert::same($console->version, 'test');
        Assert::same($console->userAgent, 'geo-registry/test');
        Assert::same($addressSources->dataMosAddressRegistrySource, '/data/datamos/registry.bson.gz');
        Assert::same($addressSources->dataMosApiKey, 'test-key');
        Assert::same($addressSources->osmAddressAliasesSource, '/data/osm/aliases.jsonl');
        Assert::same($addressSources->moscowRegionCode, 77);
        Assert::same($http->listen, '0.0.0.0:9000');
        Assert::same($http->timeoutSeconds, 3.5);
        Assert::same($http->workers, 3);
        Assert::same($http->shutdownTimeoutSeconds, 25.0);
        Assert::same($http->workerMaxRequests, 12000);
        Assert::same($http->workerMaxMemoryMegabytes, 384);
        Assert::same($http->workerMaxMemoryBytes(), 384 * 1024 * 1024);
        Assert::same($http->workerRestartBurst, 8);
        Assert::same($http->workerRestartWindowSeconds, 45.0);
        Assert::same($http->workerRestartBackoffInitialSeconds, 0.75);
        Assert::same($http->workerRestartBackoffMaximumSeconds, 20.0);
        Assert::same($http->connectionLimit, 700);
        Assert::same($http->connectionsPerIpLimit, 0);
        Assert::same($http->concurrencyLimit, 350);
        Assert::same($http->backlog, 900);
        Assert::same($http->compressionEnabled, false);
        Assert::same($logging->service, 'geo-registry-test');
        Assert::same($logging->format, LogFormat::Console);
        Assert::same($postgres->dsn, 'host=postgres db=geo');
        Assert::same($postgres->poolSize, 7);
        Assert::same($nominatim->url, 'https://nominatim.example');
        Assert::same($nominatim->language, 'ru-RU');
        Assert::same($nominatim->enabled, true);
        Assert::same($openSearch->url, 'https://search.example:9200');
        Assert::same($openSearch->index, 'addresses');
        Assert::same($openSearch->enabled, true);
        Assert::same($openSearch->searchEnabled, true);
        Assert::same($openSearch->coordinateUpdatesEnabled, true);
        Assert::same($openSearch->timeoutSeconds, 90.0);
        Assert::same($openSearch->numberOfShards, 3);
        Assert::same($openSearch->numberOfReplicas, 1);
        Assert::same($openSearch->bulkNumberOfReplicas, 0);
        Assert::same($openSearch->refreshInterval, '2s');
        Assert::same($openSearch->bulkRefreshInterval, '-1');
        Assert::same($openSearch->reindexMemoryLimit, '1G');
    }

    #[ExpectException(InvalidArgumentException::class)]
    public function testOpenSearchPasswordRequiresAUsername(): void
    {
        OpenSearchConfig::fromEnvironment(new EnvironmentSnapshot([
            'OPENSEARCH_PASSWORD' => 'secret',
        ]));
    }

    #[ExpectException(InvalidArgumentException::class)]
    public function testOpenSearchSearchFlagMustBeBoolean(): void
    {
        OpenSearchConfig::fromEnvironment(new EnvironmentSnapshot([
            'OPENSEARCH_SEARCH_ENABLED' => 'sometimes',
        ]));
    }

    #[ExpectException(InvalidArgumentException::class)]
    public function testOpenSearchFeatureRequiresTheIntegrationToBeEnabled(): void
    {
        OpenSearchConfig::fromEnvironment(new EnvironmentSnapshot([
            'OPENSEARCH_ENABLED' => 'false',
            'OPENSEARCH_SEARCH_ENABLED' => 'true',
        ]));
    }

    #[ExpectException(InvalidArgumentException::class)]
    public function testNominatimEnabledFlagMustBeBoolean(): void
    {
        NominatimConfig::fromEnvironment(new EnvironmentSnapshot([
            'NOMINATIM_ENABLED' => 'sometimes',
        ]));
    }

    #[ExpectException(InvalidArgumentException::class)]
    public function testOpenSearchRejectsAnInvalidRefreshInterval(): void
    {
        OpenSearchConfig::fromEnvironment(new EnvironmentSnapshot([
            'OPENSEARCH_REFRESH_INTERVAL' => 'immediately',
        ]));
    }

    #[ExpectException(InvalidArgumentException::class)]
    public function testOpenSearchRejectsAnUnsafeIndexAlias(): void
    {
        OpenSearchConfig::fromEnvironment(new EnvironmentSnapshot([
            'OPENSEARCH_INDEX' => 'Unsafe Alias',
        ]));
    }

    #[ExpectException(InvalidArgumentException::class)]
    public function testOpenSearchRejectsAnInvalidReindexMemoryLimit(): void
    {
        OpenSearchConfig::fromEnvironment(new EnvironmentSnapshot([
            'OPENSEARCH_REINDEX_MEMORY_LIMIT' => 'unlimited',
        ]));
    }

    #[ExpectException(InvalidArgumentException::class)]
    public function testNominatimUrlMustBeAbsoluteHttpUrl(): void
    {
        NominatimConfig::fromEnvironment(new EnvironmentSnapshot([
            'NOMINATIM_URL' => 'file:///tmp/nominatim',
        ]));
    }

    #[ExpectException(InvalidArgumentException::class)]
    public function testAddressSourcesRejectAnInvalidMoscowRegionCode(): void
    {
        AddressSourceConfig::fromEnvironment(new EnvironmentSnapshot([
            'MOSCOW_REGION_CODE' => '100',
        ]));
    }

    public function testLoggingDefaultsToJson(): void
    {
        Assert::same(
            LoggingConfig::fromEnvironment(new EnvironmentSnapshot([]))->format,
            LogFormat::Json,
        );
    }

    #[ExpectException(InvalidArgumentException::class)]
    public function testHttpWorkerCountHasAnUpperBound(): void
    {
        HttpServerConfig::fromEnvironment(new EnvironmentSnapshot([
            'APP_HTTP_WORKERS' => '65',
        ]));
    }

    #[ExpectException(InvalidArgumentException::class)]
    public function testHttpPerIpConnectionLimitMustBeNonNegative(): void
    {
        HttpServerConfig::fromEnvironment(new EnvironmentSnapshot([
            'APP_HTTP_CONNECTIONS_PER_IP_LIMIT' => '-1',
        ]));
    }

    #[ExpectException(InvalidArgumentException::class)]
    public function testHttpWorkerMemoryLimitMustFitIntoBytes(): void
    {
        HttpServerConfig::fromEnvironment(new EnvironmentSnapshot([
            'APP_HTTP_WORKER_MAX_MEMORY_MB' => (string) (intdiv(PHP_INT_MAX, 1024 * 1024) + 1),
        ]));
    }

    #[ExpectException(InvalidArgumentException::class)]
    public function testHttpWorkerRestartMaximumBackoffCannotBeLessThanInitialBackoff(): void
    {
        HttpServerConfig::fromEnvironment(new EnvironmentSnapshot([
            'APP_HTTP_WORKER_RESTART_BACKOFF_INITIAL_SECONDS' => '2',
            'APP_HTTP_WORKER_RESTART_BACKOFF_MAX_SECONDS' => '1',
        ]));
    }

    #[ExpectException(InvalidArgumentException::class)]
    public function testLoggingRejectsAnUnknownFormat(): void
    {
        LoggingConfig::fromEnvironment(new EnvironmentSnapshot([
            'LOG_FORMAT' => 'pretty',
        ]));
    }
}
