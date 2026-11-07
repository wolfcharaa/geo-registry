<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Process;

use App\Infrastructure\Process\ProcessRestartBackoff;
use Testo\Assert;
use Testo\Test;

#[Test]
final class ProcessRestartBackoffTest
{
    public function testItExcludesInitialStartsAndBacksOffRapidRestarts(): void
    {
        $backoff = new ProcessRestartBackoff(
            initialStartCount: 2,
            restartBurst: 2,
            windowSeconds: 10,
            initialDelaySeconds: 0.5,
            maximumDelaySeconds: 2,
        );

        Assert::same($backoff->beforeStart(0)->delaySeconds, 0.0);
        Assert::same($backoff->beforeStart(0.1)->delaySeconds, 0.0);

        $firstRestart = $backoff->beforeStart(1);
        Assert::same($firstRestart->restartsInWindow, 1);
        Assert::same($firstRestart->delaySeconds, 0.0);
        Assert::same($backoff->beforeStart(2)->delaySeconds, 0.0);
        Assert::same($backoff->beforeStart(3)->delaySeconds, 0.5);
        Assert::same($backoff->beforeStart(4)->delaySeconds, 1.0);
        Assert::same($backoff->beforeStart(5)->delaySeconds, 2.0);
        Assert::same($backoff->beforeStart(6)->delaySeconds, 2.0);
    }

    public function testItResetsTheRestartBurstAfterAStableWindow(): void
    {
        $backoff = new ProcessRestartBackoff(
            initialStartCount: 1,
            restartBurst: 1,
            windowSeconds: 5,
            initialDelaySeconds: 1,
            maximumDelaySeconds: 4,
        );

        $backoff->beforeStart(0);
        Assert::same($backoff->beforeStart(1)->delaySeconds, 0.0);
        Assert::same($backoff->beforeStart(2)->delaySeconds, 1.0);

        $afterStableWindow = $backoff->beforeStart(8);
        Assert::same($afterStableWindow->restartsInWindow, 1);
        Assert::same($afterStableWindow->delaySeconds, 0.0);
    }
}
