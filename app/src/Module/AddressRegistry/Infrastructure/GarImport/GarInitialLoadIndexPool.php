<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use Amp\Cancellation;

use function Amp\delay;

use Amp\Parallel\Context\ProcessContextFactory;
use Amp\Parallel\Worker\ContextWorkerFactory;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\Execution;
use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use Closure;
use Throwable;

final readonly class GarInitialLoadIndexPool
{
    public function __construct(
        private string $databaseDsn,
        private string $consolePath,
        private GarImportConnectionFactory $connectionFactory,
    ) {}

    /** @param list<GarIndexSnapshot> $indexes */
    public function run(
        int $operationId,
        array $indexes,
        int $workerCount,
        bool $persistentWorkers = false,
        ?Cancellation $cancellation = null,
        bool $manageSignals = true,
    ): void {
        if ($workerCount < 1 || $workerCount > 4) {
            throw new GarImportFailed('GAR index build worker count must be between 1 and 4.');
        }
        if ($persistentWorkers) {
            $this->runPersistent($operationId, $indexes, $workerCount, $cancellation, $manageSignals);

            return;
        }
        $interruptedSignal = null;
        $previousAsyncSignals = \pcntl_async_signals();
        $previousInterruptHandler = \pcntl_signal_get_handler(SIGINT);
        $previousTerminateHandler = \pcntl_signal_get_handler(SIGTERM);
        $interrupt = static function (int $signal) use (&$interruptedSignal): void {
            $interruptedSignal = $signal;
        };
        if ($manageSignals) {
            \pcntl_async_signals(true);
            \pcntl_signal(SIGINT, $interrupt);
            \pcntl_signal(SIGTERM, $interrupt);
        }

        /** @var list<array{process: GarImportWorkerProcess, snapshot: GarIndexSnapshot}> $active */
        $active = [];
        try {
            while ($indexes !== [] || $active !== []) {
                $cancellation?->throwIfRequested();
                if ($interruptedSignal !== null) {
                    throw new GarImportFailed(
                        "GAR index build pool was interrupted by signal {$interruptedSignal}.",
                    );
                }
                while (\count($active) < $workerCount) {
                    $position = $this->nextRunnable($indexes, $active);
                    if ($position === null) {
                        break;
                    }
                    if (!isset($indexes[$position])) {
                        throw new GarImportFailed('GAR index build scheduler selected a missing queue item.');
                    }
                    $snapshot = $indexes[$position];
                    \array_splice($indexes, $position, 1);
                    $active[] = [
                        'process' => $this->startProcess($operationId, $snapshot),
                        'snapshot' => $snapshot,
                    ];
                }

                $running = [];
                foreach ($active as $item) {
                    $result = $item['process']->poll();
                    if ($result === null) {
                        $running[] = $item;
                        continue;
                    }
                    if ($result->exitCode !== 0) {
                        $details = $result->standardError !== ''
                            ? $result->standardError
                            : $result->standardOutput;
                        throw new GarImportFailed(\trim(\sprintf(
                            '%s exited with code %d. %s',
                            $result->description,
                            $result->exitCode,
                            $details,
                        )));
                    }
                }
                $active = $running;
                if ($active !== []) {
                    delay(0.05, cancellation: $cancellation);
                }
            }
        } catch (Throwable $error) {
            foreach ($active as $item) {
                $item['process']->terminate();
            }
            throw $error;
        } finally {
            if ($manageSignals) {
                \pcntl_signal(SIGINT, $previousInterruptHandler);
                \pcntl_signal(SIGTERM, $previousTerminateHandler);
                \pcntl_async_signals($previousAsyncSignals);
            }
        }
    }

    /** @param Closure(): void $import */
    public function runAlongsideImport(
        int $operationId,
        int $workerCount,
        int $startPercent,
        Closure $import,
        bool $persistentWorkers = false,
    ): void {
        $scheduler = $this->startSchedulerProcess(
            $operationId,
            $workerCount,
            $startPercent,
            $persistentWorkers,
        );

        try {
            $import();
            $this->waitForProcess($scheduler);
        } catch (Throwable $error) {
            $scheduler->terminate();
            throw $error;
        }
    }

    public function runForCompletedTables(
        int $operationId,
        int $workerCount,
        int $startPercent,
        bool $persistentWorkers = false,
    ): void {
        if ($workerCount < 1 || $workerCount > 4) {
            throw new GarImportFailed('GAR index build worker count must be between 1 and 4.');
        }
        if ($persistentWorkers) {
            $this->runPersistentForCompletedTables($operationId, $workerCount, $startPercent);

            return;
        }
        $interruptedSignal = null;
        $previousAsyncSignals = \pcntl_async_signals(true);
        $previousInterruptHandler = \pcntl_signal_get_handler(SIGINT);
        $previousTerminateHandler = \pcntl_signal_get_handler(SIGTERM);
        $interrupt = static function (int $signal) use (&$interruptedSignal): void {
            $interruptedSignal = $signal;
        };
        \pcntl_signal(SIGINT, $interrupt);
        \pcntl_signal(SIGTERM, $interrupt);
        $connection = $this->connectionFactory->connect();
        $store = new GarInitialLoadStore($connection);
        /** @var list<array{process: GarImportWorkerProcess, snapshot: GarIndexSnapshot}> $active */
        $active = [];

        try {
            while (true) {
                if ($interruptedSignal !== null) {
                    throw new GarImportFailed(
                        "GAR index scheduler was interrupted by signal {$interruptedSignal}.",
                    );
                }
                $running = [];
                foreach ($active as $item) {
                    $result = $item['process']->poll();
                    if ($result === null) {
                        $running[] = $item;
                        continue;
                    }
                    if ($result->exitCode !== 0) {
                        $details = $result->standardError !== ''
                            ? $result->standardError
                            : $result->standardOutput;
                        throw new GarImportFailed(\trim(\sprintf(
                            '%s exited with code %d. %s',
                            $result->description,
                            $result->exitCode,
                            $details,
                        )));
                    }
                }
                $active = $running;

                $importCompleted = $store->rawImportCompleted($operationId);
                if (!$importCompleted && $store->completedSourcePercentAtLeast($operationId, $startPercent)) {
                    $indexes = \array_values(\array_filter(
                        $store->pendingIndexesForCompletedTables($operationId),
                        static function (GarIndexSnapshot $index) use ($active): bool {
                            foreach ($active as $item) {
                                if ($item['snapshot']->indexName === $index->indexName) {
                                    return false;
                                }
                            }

                            return true;
                        },
                    ));
                    while (\count($active) < $workerCount) {
                        $position = $this->nextRunnable($indexes, $active);
                        if ($position === null) {
                            break;
                        }
                        if (!isset($indexes[$position])) {
                            throw new GarImportFailed('GAR early index scheduler selected a missing queue item.');
                        }
                        $snapshot = $indexes[$position];
                        \array_splice($indexes, $position, 1);
                        $active[] = [
                            'process' => $this->startProcess($operationId, $snapshot),
                            'snapshot' => $snapshot,
                        ];
                    }
                }

                if ($importCompleted && $active === []) {
                    return;
                }
                \usleep(50_000);
            }
        } catch (Throwable $error) {
            foreach ($active as $item) {
                $item['process']->terminate();
            }
            throw $error;
        } finally {
            \pg_close($connection);
            \pcntl_signal(SIGINT, $previousInterruptHandler);
            \pcntl_signal(SIGTERM, $previousTerminateHandler);
            \pcntl_async_signals($previousAsyncSignals);
        }
    }

    private function startSchedulerProcess(
        int $operationId,
        int $workerCount,
        int $startPercent,
        bool $persistentWorkers,
    ): GarImportWorkerProcess {
        if ($workerCount < 1 || $workerCount > 4) {
            throw new GarImportFailed('GAR index build worker count must be between 1 and 4.');
        }
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = \proc_open(
            [
                PHP_BINARY,
                $this->consolePath,
                'gar:import:index-scheduler',
                (string) $operationId,
                (string) $workerCount,
                (string) $startPercent,
                $persistentWorkers ? '1' : '0',
                '--no-interaction',
                '--no-ansi',
            ],
            $descriptors,
            $pipes,
            \dirname($this->consolePath, 2),
            $this->environment(),
        );
        if (!\is_resource($process)) {
            throw new GarImportFailed('Unable to start the GAR index scheduler process.');
        }
        if (
            !isset($pipes[0], $pipes[1], $pipes[2])
            || !\is_resource($pipes[0])
            || !\is_resource($pipes[1])
            || !\is_resource($pipes[2])
        ) {
            \proc_terminate($process);
            \proc_close($process);
            throw new GarImportFailed('Unable to open GAR index scheduler process pipes.');
        }
        \fclose($pipes[0]);

        return new GarImportWorkerProcess(
            process: $process,
            standardOutput: $pipes[1],
            standardError: $pipes[2],
            description: 'index-scheduler',
        );
    }

    private function waitForProcess(GarImportWorkerProcess $process): void
    {
        while (true) {
            $result = $process->poll();
            if ($result === null) {
                \usleep(50_000);
                continue;
            }
            if ($result->exitCode === 0) {
                return;
            }
            $details = $result->standardError !== ''
                ? $result->standardError
                : $result->standardOutput;
            throw new GarImportFailed(\trim(\sprintf(
                '%s exited with code %d. %s',
                $result->description,
                $result->exitCode,
                $details,
            )));
        }
    }

    /** @param list<GarIndexSnapshot> $indexes */
    private function runPersistent(
        int $operationId,
        array $indexes,
        int $workerCount,
        ?Cancellation $cancellation,
        bool $manageSignals,
    ): void {
        $interruptedSignal = null;
        $previousAsyncSignals = \pcntl_async_signals();
        $previousInterruptHandler = \pcntl_signal_get_handler(SIGINT);
        $previousTerminateHandler = \pcntl_signal_get_handler(SIGTERM);
        $interrupt = static function (int $signal) use (&$interruptedSignal): void {
            $interruptedSignal = $signal;
        };
        if ($manageSignals) {
            \pcntl_async_signals(true);
            \pcntl_signal(SIGINT, $interrupt);
            \pcntl_signal(SIGTERM, $interrupt);
        }
        $pool = $this->persistentPool($workerCount);
        /** @var list<array{execution: Execution<GarIndexBuildTaskResult, never, never>, snapshot: GarIndexSnapshot}> $active */
        $active = [];

        try {
            while ($indexes !== [] || $active !== []) {
                $cancellation?->throwIfRequested();
                if ($interruptedSignal !== null) {
                    throw new GarImportFailed(
                        "GAR persistent index build pool was interrupted by signal {$interruptedSignal}.",
                    );
                }
                while (\count($active) < $workerCount) {
                    $position = $this->nextRunnable($indexes, $active);
                    if ($position === null) {
                        break;
                    }
                    if (!isset($indexes[$position])) {
                        throw new GarImportFailed('GAR persistent index scheduler selected a missing queue item.');
                    }
                    $snapshot = $indexes[$position];
                    \array_splice($indexes, $position, 1);
                    $active[] = [
                        'execution' => $this->submitTask($pool, $operationId, $snapshot),
                        'snapshot' => $snapshot,
                    ];
                }

                $active = $this->pollTasks($active);
                if ($active !== []) {
                    delay(0.05, cancellation: $cancellation);
                }
            }

            $pool->shutdown();
        } catch (Throwable $error) {
            if ($pool->isRunning()) {
                $pool->kill();
            }
            throw $error;
        } finally {
            if ($pool->isRunning()) {
                $pool->kill();
            }
            if ($manageSignals) {
                \pcntl_signal(SIGINT, $previousInterruptHandler);
                \pcntl_signal(SIGTERM, $previousTerminateHandler);
                \pcntl_async_signals($previousAsyncSignals);
            }
        }
    }

    private function runPersistentForCompletedTables(
        int $operationId,
        int $workerCount,
        int $startPercent,
    ): void {
        $interruptedSignal = null;
        $previousAsyncSignals = \pcntl_async_signals(true);
        $previousInterruptHandler = \pcntl_signal_get_handler(SIGINT);
        $previousTerminateHandler = \pcntl_signal_get_handler(SIGTERM);
        $interrupt = static function (int $signal) use (&$interruptedSignal): void {
            $interruptedSignal = $signal;
        };
        \pcntl_signal(SIGINT, $interrupt);
        \pcntl_signal(SIGTERM, $interrupt);
        $connection = $this->connectionFactory->connect();
        $store = new GarInitialLoadStore($connection);
        $pool = $this->persistentPool($workerCount);
        /** @var list<array{execution: Execution<GarIndexBuildTaskResult, never, never>, snapshot: GarIndexSnapshot}> $active */
        $active = [];

        try {
            while (true) {
                if ($interruptedSignal !== null) {
                    throw new GarImportFailed(
                        "GAR persistent index scheduler was interrupted by signal {$interruptedSignal}.",
                    );
                }
                $active = $this->pollTasks($active);

                $importCompleted = $store->rawImportCompleted($operationId);
                if (!$importCompleted && $store->completedSourcePercentAtLeast($operationId, $startPercent)) {
                    $indexes = \array_values(\array_filter(
                        $store->pendingIndexesForCompletedTables($operationId),
                        static function (GarIndexSnapshot $index) use ($active): bool {
                            foreach ($active as $item) {
                                if ($item['snapshot']->indexName === $index->indexName) {
                                    return false;
                                }
                            }

                            return true;
                        },
                    ));
                    while (\count($active) < $workerCount) {
                        $position = $this->nextRunnable($indexes, $active);
                        if ($position === null) {
                            break;
                        }
                        if (!isset($indexes[$position])) {
                            throw new GarImportFailed(
                                'GAR persistent early index scheduler selected a missing queue item.',
                            );
                        }
                        $snapshot = $indexes[$position];
                        \array_splice($indexes, $position, 1);
                        $active[] = [
                            'execution' => $this->submitTask($pool, $operationId, $snapshot),
                            'snapshot' => $snapshot,
                        ];
                    }
                }

                if ($importCompleted && $active === []) {
                    $pool->shutdown();

                    return;
                }
                delay(0.05);
            }
        } catch (Throwable $error) {
            if ($pool->isRunning()) {
                $pool->kill();
            }
            throw $error;
        } finally {
            if ($pool->isRunning()) {
                $pool->kill();
            }
            \pg_close($connection);
            \pcntl_signal(SIGINT, $previousInterruptHandler);
            \pcntl_signal(SIGTERM, $previousTerminateHandler);
            \pcntl_async_signals($previousAsyncSignals);
        }
    }

    private function persistentPool(int $workerCount): ContextWorkerPool
    {
        return new ContextWorkerPool(
            $workerCount,
            new ContextWorkerFactory(
                contextFactory: new ProcessContextFactory(
                    workingDirectory: \dirname($this->consolePath, 2),
                    environment: $this->environment(),
                ),
            ),
        );
    }

    /** @return Execution<GarIndexBuildTaskResult, never, never> */
    private function submitTask(
        ContextWorkerPool $pool,
        int $operationId,
        GarIndexSnapshot $snapshot,
    ): Execution {
        return $pool->submit(new GarIndexBuildTask(
            $this->databaseDsn,
            $operationId,
            $snapshot->indexName,
        ));
    }

    /**
     * @param list<array{execution: Execution<GarIndexBuildTaskResult, never, never>, snapshot: GarIndexSnapshot}> $active
     * @return list<array{execution: Execution<GarIndexBuildTaskResult, never, never>, snapshot: GarIndexSnapshot}>
     */
    private function pollTasks(array $active): array
    {
        $running = [];
        foreach ($active as $item) {
            $future = $item['execution']->getFuture();
            if (!$future->isComplete()) {
                $running[] = $item;
                continue;
            }

            try {
                $result = $future->await();
            } catch (Throwable $error) {
                throw new GarImportFailed(
                    "index-worker[{$item['snapshot']->indexName}] failed. {$error->getMessage()}",
                    previous: $error,
                );
            }
            if ($result->indexName !== $item['snapshot']->indexName || $result->backendPid < 1) {
                throw new GarImportFailed('GAR persistent index worker returned an unexpected index result.');
            }
        }

        return $running;
    }

    /**
     * @param list<GarIndexSnapshot> $indexes
     * @param list<array{snapshot: GarIndexSnapshot, ...}> $active
     */
    private function nextRunnable(array $indexes, array $active): ?int
    {
        $activeTables = [];
        foreach ($active as $item) {
            $activeTables[$item['snapshot']->tableName] = true;
        }
        foreach ($indexes as $position => $index) {
            if (!isset($activeTables[$index->tableName])) {
                return $position;
            }
        }

        return null;
    }

    private function startProcess(int $operationId, GarIndexSnapshot $snapshot): GarImportWorkerProcess
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = \proc_open(
            [
                PHP_BINARY,
                $this->consolePath,
                'gar:import:index-worker',
                (string) $operationId,
                $snapshot->indexName,
                '--no-interaction',
                '--no-ansi',
            ],
            $descriptors,
            $pipes,
            \dirname($this->consolePath, 2),
            $this->environment(),
        );
        if (!\is_resource($process)) {
            throw new GarImportFailed('Unable to start a GAR index build worker process.');
        }
        if (
            !isset($pipes[0], $pipes[1], $pipes[2])
            || !\is_resource($pipes[0])
            || !\is_resource($pipes[1])
            || !\is_resource($pipes[2])
        ) {
            \proc_terminate($process);
            \proc_close($process);
            throw new GarImportFailed('Unable to open GAR index build worker process pipes.');
        }
        \fclose($pipes[0]);

        return new GarImportWorkerProcess(
            process: $process,
            standardOutput: $pipes[1],
            standardError: $pipes[2],
            description: "index-worker[{$snapshot->indexName}]",
        );
    }

    /** @return array<string, string> */
    private function environment(): array
    {
        $environment = \getenv();
        $environment['DATABASE_DSN'] = $this->databaseDsn;

        return $environment;
    }
}
