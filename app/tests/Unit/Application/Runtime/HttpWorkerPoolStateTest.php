<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Runtime;

use App\Application\Runtime\HttpWorkerPoolState;
use App\Application\Runtime\HttpWorkerPoolTransition;
use App\Application\Runtime\HttpWorkerSignal;
use App\Application\Runtime\HttpWorkerSignalType;
use Testo\Assert;
use Testo\Test;

#[Test]
final class HttpWorkerPoolStateTest
{
    public function testItReportsCapacityTransitionsWithoutAlertingDuringInitialStartup(): void
    {
        $pool = new HttpWorkerPoolState(expectedWorkers: 2, heartbeatTimeoutSeconds: 10);
        $alive = static fn(int $pid): bool => $pid > 0;

        $pool->observe(new HttpWorkerSignal(HttpWorkerSignalType::Online, 1, 101), 1);
        Assert::null($pool->inspect(1, $alive)->transition);

        $pool->observe(new HttpWorkerSignal(HttpWorkerSignalType::Online, 2, 102), 2);
        Assert::same($pool->inspect(2, $alive)->transition, HttpWorkerPoolTransition::Ready);

        $pool->observe(new HttpWorkerSignal(HttpWorkerSignalType::Offline, 1, 101), 3);
        Assert::same($pool->inspect(3, $alive)->transition, HttpWorkerPoolTransition::Degraded);
        Assert::null($pool->inspect(4, $alive)->transition);

        $pool->observe(new HttpWorkerSignal(HttpWorkerSignalType::Online, 3, 103), 5);
        Assert::same($pool->inspect(5, $alive)->transition, HttpWorkerPoolTransition::Restored);
    }

    public function testItRemovesDeadAndStaleWorkersFromObservedCapacity(): void
    {
        $pool = new HttpWorkerPoolState(expectedWorkers: 2, heartbeatTimeoutSeconds: 3);
        $pool->observe(new HttpWorkerSignal(HttpWorkerSignalType::Online, 1, 101), 0);
        $pool->observe(new HttpWorkerSignal(HttpWorkerSignalType::Online, 2, 102), 0);
        $pool->inspect(0, static fn(int $pid): bool => $pid > 0);

        $pool->observe(new HttpWorkerSignal(HttpWorkerSignalType::Heartbeat, 1, 101), 2);
        $observation = $pool->inspect(4, static fn(int $pid): bool => $pid !== 101);

        Assert::same($observation->activeWorkers, 0);
        Assert::same($observation->removedWorkerIds, [1, 2]);
        Assert::same($observation->transition, HttpWorkerPoolTransition::Degraded);
    }
}
