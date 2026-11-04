<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\DependencyInjection;

use App\Application\DependencyInjection\WorkerRuntimeModule;
use App\Config\EnvironmentSnapshot;
use Fedor\MessageBus\Worker\Control\WorkerControlRuntime;
use Fedor\MessageBus\Worker\Queue\QueueWorkerRunner;
use Fedor\MessageBus\Worker\Runtime\WorkerRuntime;
use Testo\Assert;
use Testo\Test;
use Thesis\Dic;

#[Test]
final class WorkerRuntimeModuleTest
{
    public function testItBuildsAndDisposesTheRealWorkerRuntimeGraph(): void
    {
        $services = Dic::run(
            new WorkerRuntimeModule(new EnvironmentSnapshot([])),
            static fn(WorkerRuntime $runtime): array => [
                $runtime::class,
                $runtime->runner::class,
                $runtime->requireControl()::class,
            ],
        );

        Assert::same($services, [
            WorkerRuntime::class,
            QueueWorkerRunner::class,
            WorkerControlRuntime::class,
        ]);
    }
}
