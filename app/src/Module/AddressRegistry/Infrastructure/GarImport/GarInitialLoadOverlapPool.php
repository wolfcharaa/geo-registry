<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use function Amp\async;

use Amp\CancelledException;
use Amp\DeferredCancellation;

use function Amp\Future\await;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use Throwable;

/** Owns the joined post-raw index/FK overlap and cancels the sibling pool on failure. */
final readonly class GarInitialLoadOverlapPool
{
    public function __construct(
        private GarInitialLoadIndexPool $indexPool,
        private GarForeignKeyValidationPool $foreignKeyPool,
    ) {}

    /**
     * @param list<GarIndexSnapshot> $indexes
     * @param list<GarForeignKeySnapshot> $foreignKeys
     */
    public function run(
        int $operationId,
        array $indexes,
        array $foreignKeys,
        int $indexWorkers,
        int $foreignKeyWorkers,
        bool $persistentIndexWorkers,
    ): void {
        $stop = new DeferredCancellation();
        $cancellation = $stop->getCancellation();
        $interruptedSignal = null;
        $previousAsyncSignals = \pcntl_async_signals(true);
        $previousInterruptHandler = \pcntl_signal_get_handler(SIGINT);
        $previousTerminateHandler = \pcntl_signal_get_handler(SIGTERM);
        $interrupt = static function (int $signal) use (&$interruptedSignal, $stop): void {
            $interruptedSignal = $signal;
            $stop->cancel();
        };
        \pcntl_signal(SIGINT, $interrupt);
        \pcntl_signal(SIGTERM, $interrupt);
        $futures = [
            'indexes' => async(fn() => $this->indexPool->run(
                $operationId,
                $indexes,
                $indexWorkers,
                $persistentIndexWorkers,
                $cancellation,
                manageSignals: false,
            )),
            'foreign-keys' => async(fn() => $this->foreignKeyPool->run(
                $operationId,
                $foreignKeys,
                $foreignKeyWorkers,
                $cancellation,
                manageSignals: false,
            )),
        ];

        try {
            await($futures);
        } catch (Throwable $primary) {
            $stop->cancel($primary);
            $cleanupFailures = [];
            foreach ($futures as $name => $future) {
                try {
                    $future->await();
                } catch (CancelledException) {
                    // Expected sibling shutdown after the primary pool failure.
                } catch (Throwable $cleanupFailure) {
                    if ($cleanupFailure !== $primary) {
                        $cleanupFailures[] = "{$name}: {$cleanupFailure->getMessage()}";
                    }
                }
            }
            if ($cleanupFailures !== []) {
                throw new GarImportFailed(
                    'GAR post-raw overlap failed and sibling cleanup also failed: '
                        . \implode('; ', $cleanupFailures),
                    previous: $primary,
                );
            }

            if ($interruptedSignal !== null) {
                throw new GarImportFailed(
                    "GAR post-raw overlap was interrupted by signal {$interruptedSignal}.",
                    previous: $primary,
                );
            }

            throw $primary;
        } finally {
            \pcntl_signal(SIGINT, $previousInterruptHandler);
            \pcntl_signal(SIGTERM, $previousTerminateHandler);
            \pcntl_async_signals($previousAsyncSignals);
        }
    }
}
