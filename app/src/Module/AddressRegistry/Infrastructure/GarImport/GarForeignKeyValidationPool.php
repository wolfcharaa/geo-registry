<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use Amp\Cancellation;

use function Amp\delay;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use Throwable;

final readonly class GarForeignKeyValidationPool
{
    public function __construct(
        private string $databaseDsn,
        private string $consolePath,
    ) {}

    /** @param list<GarForeignKeySnapshot> $constraints */
    public function run(
        int $operationId,
        array $constraints,
        int $workerCount,
        ?Cancellation $cancellation = null,
        bool $manageSignals = true,
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

        /** @var list<array{process: GarImportWorkerProcess, snapshot: GarForeignKeySnapshot}> $active */
        $active = [];
        try {
            while ($constraints !== [] || $active !== []) {
                $cancellation?->throwIfRequested();
                if ($interruptedSignal !== null) {
                    throw new GarImportFailed(
                        "GAR foreign-key validation pool was interrupted by signal {$interruptedSignal}.",
                    );
                }
                while (\count($active) < $workerCount) {
                    $position = $this->nextRunnable($constraints, $active);
                    if ($position === null) {
                        break;
                    }
                    if (!isset($constraints[$position])) {
                        throw new GarImportFailed('GAR foreign-key scheduler selected a missing queue item.');
                    }
                    $snapshot = $constraints[$position];
                    \array_splice($constraints, $position, 1);
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

    /**
     * @param list<GarForeignKeySnapshot> $constraints
     * @param list<array{process: GarImportWorkerProcess, snapshot: GarForeignKeySnapshot}> $active
     */
    private function nextRunnable(array $constraints, array $active): ?int
    {
        $activeTables = [];
        foreach ($active as $item) {
            $activeTables[$item['snapshot']->tableName] = true;
        }
        foreach ($constraints as $position => $constraint) {
            if (!isset($activeTables[$constraint->tableName])) {
                return $position;
            }
        }

        return null;
    }

    private function startProcess(int $operationId, GarForeignKeySnapshot $snapshot): GarImportWorkerProcess
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
                'gar:import:foreign-key-worker',
                (string) $operationId,
                $snapshot->tableName,
                $snapshot->constraintName,
                '--no-interaction',
                '--no-ansi',
            ],
            $descriptors,
            $pipes,
            \dirname($this->consolePath, 2),
            $this->environment(),
        );
        if (!\is_resource($process)) {
            throw new GarImportFailed('Unable to start a GAR foreign-key validation worker process.');
        }
        if (
            !isset($pipes[0], $pipes[1], $pipes[2])
            || !\is_resource($pipes[0])
            || !\is_resource($pipes[1])
            || !\is_resource($pipes[2])
        ) {
            \proc_terminate($process);
            \proc_close($process);
            throw new GarImportFailed('Unable to open GAR foreign-key validation worker process pipes.');
        }
        \fclose($pipes[0]);

        return new GarImportWorkerProcess(
            process: $process,
            standardOutput: $pipes[1],
            standardError: $pipes[2],
            description: "foreign-key-worker[{$snapshot->tableName}.{$snapshot->constraintName}]",
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
