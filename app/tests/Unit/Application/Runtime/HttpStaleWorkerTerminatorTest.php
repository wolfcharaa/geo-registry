<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Runtime;

use function Amp\delay;

use App\Application\Runtime\HttpStaleWorkerTerminator;
use App\Application\Runtime\HttpWorkerProcess;
use Psr\Log\NullLogger;
use Testo\Assert;
use Testo\Test;

#[Test]
final class HttpStaleWorkerTerminatorTest
{
    public function testItTerminatesThenForceKillsOnlyTheSameProcessIdentity(): void
    {
        $signals = [];
        $terminator = new HttpStaleWorkerTerminator(
            graceSeconds: 0.001,
            logger: new NullLogger(),
            sendSignal: static function (int $pid, int $signal) use (&$signals): bool {
                $signals[] = [$pid, $signal];

                return true;
            },
            processIdentity: static fn(int $pid): ?string => $pid === 101 ? 'start-1' : null,
        );

        $worker = new HttpWorkerProcess(1, 101);
        $terminator->terminate($worker);
        $terminator->terminate($worker);

        Assert::same($signals, [[101, SIGTERM]]);
        Assert::same($terminator->pendingTerminations(), 1);

        delay(0.003);

        Assert::same($signals, [[101, SIGTERM], [101, SIGKILL]]);
        Assert::same($terminator->pendingTerminations(), 0);
    }

    public function testItDoesNotSignalAReusedPidAfterTheGracePeriod(): void
    {
        $signals = [];
        $identityReads = 0;
        $terminator = new HttpStaleWorkerTerminator(
            graceSeconds: 0.001,
            logger: new NullLogger(),
            sendSignal: static function (int $pid, int $signal) use (&$signals): bool {
                $signals[] = [$pid, $signal];

                return true;
            },
            processIdentity: static function (int $pid) use (&$identityReads): string {
                ++$identityReads;

                return $identityReads === 1 ? 'start-old' : 'start-reused';
            },
        );

        $terminator->terminate(new HttpWorkerProcess(2, 202));
        delay(0.003);

        Assert::same($signals, [[202, SIGTERM]]);
        Assert::same($terminator->pendingTerminations(), 0);
    }
}
