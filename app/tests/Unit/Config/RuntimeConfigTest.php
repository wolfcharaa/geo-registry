<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

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
            'DATABASE_DSN' => 'host=postgres db=geo',
            'DATABASE_POOL_SIZE' => '7',
            'NOMINATIM_URL' => 'https://nominatim.example',
            'NOMINATIM_LANGUAGE' => 'ru-RU',
            'OPENSEARCH_URL' => 'https://search.example:9200',
            'OPENSEARCH_INDEX' => 'addresses',
            'OPENSEARCH_SEARCH_ENABLED' => 'true',
        ]);

        $http = HttpServerConfig::fromEnvironment($environment);
        $logging = LoggingConfig::fromEnvironment($environment);
        $console = ConsoleConfig::fromEnvironment($environment);
        $postgres = PostgresConfig::fromEnvironment($environment);
        $nominatim = NominatimConfig::fromEnvironment($environment);
        $openSearch = OpenSearchConfig::fromEnvironment($environment);

        Assert::same($console->name, 'geo-registry-test');
        Assert::same($console->version, 'test');
        Assert::same($http->listen, '0.0.0.0:9000');
        Assert::same($http->timeoutSeconds, 3.5);
        Assert::same($logging->service, 'geo-registry-test');
        Assert::same($logging->format, LogFormat::Console);
        Assert::same($postgres->dsn, 'host=postgres db=geo');
        Assert::same($postgres->poolSize, 7);
        Assert::same($nominatim->url, 'https://nominatim.example');
        Assert::same($nominatim->language, 'ru-RU');
        Assert::same($openSearch->url, 'https://search.example:9200');
        Assert::same($openSearch->index, 'addresses');
        Assert::same($openSearch->searchEnabled, true);
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
    public function testNominatimUrlMustBeAbsoluteHttpUrl(): void
    {
        NominatimConfig::fromEnvironment(new EnvironmentSnapshot([
            'NOMINATIM_URL' => 'file:///tmp/nominatim',
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
