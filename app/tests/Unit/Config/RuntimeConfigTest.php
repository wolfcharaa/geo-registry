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
            'HTTP_TIMEOUT_SECONDS' => '3.5',
            'LOG_FORMAT' => 'console',
            'DATAMOS_ADDRESS_REGISTRY_SOURCE' => '/data/datamos/registry.bson.gz',
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
        Assert::same($addressSources->osmAddressAliasesSource, '/data/osm/aliases.jsonl');
        Assert::same($addressSources->moscowRegionCode, 77);
        Assert::same($http->listen, '0.0.0.0:9000');
        Assert::same($http->timeoutSeconds, 3.5);
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
    public function testLoggingRejectsAnUnknownFormat(): void
    {
        LoggingConfig::fromEnvironment(new EnvironmentSnapshot([
            'LOG_FORMAT' => 'pretty',
        ]));
    }
}
