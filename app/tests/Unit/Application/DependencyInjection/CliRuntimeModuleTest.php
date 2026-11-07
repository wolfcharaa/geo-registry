<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\DependencyInjection;

use App\Application\DependencyInjection\CliRuntimeModule;
use App\Application\MessageBus\MessageBusRegistrySource;
use App\Application\MessageBus\MessageCodecRegistryLoader;
use App\Application\Runtime\CliRuntime;
use App\Config\EnvironmentSnapshot;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Test;
use Thesis\Dic;

#[Test]
final class CliRuntimeModuleTest
{
    public function testItRunsTheConsoleFromAnIndependentGraph(): void
    {
        $output = new BufferedOutput();
        $exitCode = Dic::run(
            new CliRuntimeModule(
                new EnvironmentSnapshot([
                    'APP_NAME' => 'geo-registry-test',
                    'APP_VERSION' => 'test',
                ]),
                MessageBusRegistrySource::fromFile(dirname(__DIR__, 4) . '/config/message_bus_registry.php'),
                MessageCodecRegistryLoader::fromFile(dirname(__DIR__, 4) . '/config/message_bus_codecs.php'),
            ),
            static fn(CliRuntime $runtime): int => $runtime->run(
                new ArrayInput(['command' => 'list', '--no-ansi' => true]),
                $output,
            ),
        );

        Assert::same($exitCode, 0);
        $console = $output->fetch();

        Assert::true(str_contains($console, 'geo-registry-test'));
        Assert::true(str_contains($console, 'migrate:up'));
        Assert::true(str_contains($console, 'migrate:down'));
        Assert::true(str_contains($console, 'migrate:history'));
        Assert::true(str_contains($console, 'migrate:mark'));
        Assert::true(str_contains($console, 'gar:import'));
        Assert::true(str_contains($console, 'gar:status'));
        Assert::true(str_contains($console, 'gar:address-search:rebuild'));
        Assert::true(str_contains($console, 'datamos:address-registry:import'));
        Assert::true(str_contains($console, 'datamos:address-registry:enrich'));
        Assert::true(str_contains($console, 'osm:address-aliases:import'));
        Assert::true(str_contains($console, 'opensearch:reindex'));
        Assert::true(str_contains($console, 'opensearch:index:cleanup'));
        Assert::true(str_contains($console, 'server:run'));
        Assert::true(str_contains($console, 'worker:run'));
        Assert::true(str_contains($console, 'worker:status'));
        Assert::false(str_contains($console, 'gar:import:worker'));
    }

    public function testTheExecutableAppIsTheSingleConsoleEntrypoint(): void
    {
        $path = dirname(__DIR__, 4) . '/bin/app';
        $source = file_get_contents($path);

        Assert::true(is_executable($path));
        Assert::true(is_string($source));
        Assert::true(str_starts_with($source, "#!/usr/bin/env php\n"));
    }

    public function testItCompilesTheExplicitMessageBusRegistry(): void
    {
        $output = new BufferedOutput();
        $exitCode = Dic::run(
            new CliRuntimeModule(
                new EnvironmentSnapshot([]),
                MessageBusRegistrySource::fromFile(dirname(__DIR__, 4) . '/config/message_bus_registry.php'),
                MessageCodecRegistryLoader::fromFile(dirname(__DIR__, 4) . '/config/message_bus_codecs.php'),
            ),
            static fn(CliRuntime $runtime): int => $runtime->run(
                new ArrayInput(['command' => 'message-bus:registry:check', '--no-ansi' => true]),
                $output,
            ),
        );

        Assert::same($exitCode, 0);
        Assert::true(str_contains($output->fetch(), 'bindings=7 source_hash='));
    }
}
