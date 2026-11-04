<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Worker;

use App\Application\MessageBus\MessageBusRegistrySource;
use App\Application\Worker\ApplicationWorkerRuntimeProvider;
use App\Config\EnvironmentSnapshot;
use Fedor\MessageBus\Worker\Cli\ApplicationFactory;
use Fedor\MessageBus\Worker\Cli\BootstrapResolver;
use Fedor\MessageBus\Worker\Control\WorkerControlRuntime;
use Fedor\MessageBus\Worker\Queue\QueueWorkerRunner;
use Fedor\MessageBus\Worker\Runtime\WorkerRuntime;
use Testo\Assert;
use Testo\Test;

#[Test]
final class ApplicationWorkerRuntimeProviderTest
{
    public function testWorkerConsoleExposesTheOperationalCommandSurface(): void
    {
        $application = ApplicationFactory::create(new BootstrapResolver([
            dirname(__DIR__, 4) . '/config/message_bus_worker.php',
        ]));

        foreach ([
            'worker:run',
            'worker:control',
            'worker:pause',
            'worker:resume',
            'worker:drain',
            'worker:stop',
            'worker:kill',
            'worker:restart',
            'worker:status',
            'worker:schema:postgres',
        ] as $command) {
            Assert::true($application->has($command));
        }
    }

    public function testItRunsTheWorkerOperationInsideTheManagedDicLifecycle(): void
    {
        $services = (new ApplicationWorkerRuntimeProvider(
            new EnvironmentSnapshot([]),
            MessageBusRegistrySource::empty(),
        ))->run(static fn(WorkerRuntime $runtime): array => [
            $runtime::class,
            $runtime->runner::class,
            $runtime->requireControl()::class,
        ]);

        Assert::same($services, [
            WorkerRuntime::class,
            QueueWorkerRunner::class,
            WorkerControlRuntime::class,
        ]);
    }
}
