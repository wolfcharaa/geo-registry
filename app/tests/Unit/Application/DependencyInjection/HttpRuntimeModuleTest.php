<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\DependencyInjection;

use App\Application\DependencyInjection\HttpRuntimeModule;
use App\Application\MessageBus\MessageBusRegistrySource;
use App\Application\MessageBus\MessageCodecRegistryLoader;
use App\Application\Runtime\HttpRuntime;
use App\Config\EnvironmentSnapshot;
use Testo\Assert;
use Testo\Test;
use Thesis\Dic;

#[Test]
final class HttpRuntimeModuleTest
{
    public function testItBuildsAndDisposesTheHttpRuntimeGraph(): void
    {
        $runtimeClass = Dic::run(
            new HttpRuntimeModule(
                new EnvironmentSnapshot([]),
                MessageBusRegistrySource::fromFile(dirname(__DIR__, 4) . '/config/message_bus_http.php'),
                MessageCodecRegistryLoader::fromFile(dirname(__DIR__, 4) . '/config/message_bus_codecs.php'),
            ),
            static fn(HttpRuntime $runtime): string => $runtime::class,
        );

        Assert::same($runtimeClass, HttpRuntime::class);
    }

    public function testItBuildsTheHttpRuntimeWithOpenSearchSearchEnabled(): void
    {
        $runtimeClass = Dic::run(
            new HttpRuntimeModule(
                new EnvironmentSnapshot(['OPENSEARCH_SEARCH_ENABLED' => 'true']),
                MessageBusRegistrySource::fromFile(dirname(__DIR__, 4) . '/config/message_bus_http.php'),
                MessageCodecRegistryLoader::fromFile(dirname(__DIR__, 4) . '/config/message_bus_codecs.php'),
            ),
            static fn(HttpRuntime $runtime): string => $runtime::class,
        );

        Assert::same($runtimeClass, HttpRuntime::class);
    }

    public function testItBuildsWithoutOptionalIntegrationGraphs(): void
    {
        $runtimeClass = Dic::run(
            new HttpRuntimeModule(
                new EnvironmentSnapshot([
                    'NOMINATIM_ENABLED' => 'false',
                    'OPENSEARCH_ENABLED' => 'false',
                ]),
                MessageBusRegistrySource::fromFile(dirname(__DIR__, 4) . '/config/message_bus_http.php'),
                MessageCodecRegistryLoader::fromFile(dirname(__DIR__, 4) . '/config/message_bus_codecs.php'),
            ),
            static fn(HttpRuntime $runtime): string => $runtime::class,
        );

        Assert::same($runtimeClass, HttpRuntime::class);
    }
}
