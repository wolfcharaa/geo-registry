<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use JsonException;
use Throwable;

final readonly class GarImportWorkerPool
{
    public function __construct(
        private string $databaseDsn,
        private string $consolePath,
        private GarImportWorkerPlan $plan,
    ) {}

    /** @param list<GarXmlSourceFile> $files */
    public function run(
        int $operationId,
        string $sourcePath,
        int $batchSize,
        int $workerCount,
        array $files,
    ): void {
        $interruptedSignal = null;
        $previousAsyncSignals = pcntl_async_signals(true);
        $previousInterruptHandler = pcntl_signal_get_handler(SIGINT);
        $previousTerminateHandler = pcntl_signal_get_handler(SIGTERM);
        $interrupt = static function (int $signal) use (&$interruptedSignal): void {
            $interruptedSignal = $signal;
        };
        pcntl_signal(SIGINT, $interrupt);
        pcntl_signal(SIGTERM, $interrupt);

        try {
            foreach ($this->plan->phases($files, $workerCount) as $assignments) {
                if ($interruptedSignal !== null) {
                    throw $this->interrupted($interruptedSignal);
                }
                $this->runPhase(
                    $operationId,
                    $sourcePath,
                    $batchSize,
                    $assignments,
                    $interruptedSignal,
                );
            }
        } finally {
            pcntl_signal(SIGINT, $previousInterruptHandler);
            pcntl_signal(SIGTERM, $previousTerminateHandler);
            pcntl_async_signals($previousAsyncSignals);
        }
    }

    /** @param list<non-empty-list<GarXmlSourceFile>> $assignments */
    private function runPhase(
        int $operationId,
        string $sourcePath,
        int $batchSize,
        array $assignments,
        ?int &$interruptedSignal,
    ): void {
        /** @var list<GarImportWorkerProcess> $processes */
        $processes = [];

        try {
            foreach ($assignments as $files) {
                if ($interruptedSignal !== null) {
                    throw $this->interrupted($interruptedSignal);
                }
                $processes[] = $this->startProcess($operationId, $sourcePath, $batchSize, $files);
            }
        } catch (Throwable $error) {
            $this->terminate($processes);
            throw $error;
        }

        $failures = [];
        try {
            while ($processes !== []) {
                if ($interruptedSignal !== null) {
                    throw $this->interrupted($interruptedSignal);
                }
                $running = [];
                foreach ($processes as $process) {
                    $result = $process->poll();
                    if ($result === null) {
                        $running[] = $process;
                        continue;
                    }
                    if ($result->exitCode !== 0) {
                        $details = $result->standardError !== ''
                            ? $result->standardError
                            : $result->standardOutput;
                        $failures[] = trim(sprintf(
                            '%s exited with code %d. %s',
                            $result->description,
                            $result->exitCode,
                            $details,
                        ));
                    }
                }
                $processes = $running;
                if ($failures !== []) {
                    throw new GarImportFailed('GAR import worker phase failed: ' . implode(' | ', $failures));
                }
                if ($processes !== []) {
                    usleep(50_000);
                }
            }
        } catch (Throwable $error) {
            $this->terminate($processes);
            throw $error;
        }
    }

    /** @param list<GarImportWorkerProcess> $processes */
    private function terminate(array $processes): void
    {
        foreach ($processes as $process) {
            $process->terminate();
        }
    }

    private function interrupted(int $signal): GarImportFailed
    {
        return new GarImportFailed("GAR import worker pool was interrupted by signal {$signal}.");
    }

    /**
     * @param non-empty-list<GarXmlSourceFile> $files
     * @throws JsonException
     */
    private function startProcess(
        int $operationId,
        string $sourcePath,
        int $batchSize,
        array $files,
    ): GarImportWorkerProcess {
        $payload = json_encode(array_map(
            static fn(GarXmlSourceFile $file): array => [
                'relative_path' => $file->relativePath,
                'file_key' => $file->key,
            ],
            $files,
        ), JSON_THROW_ON_ERROR);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = proc_open(
            [
                PHP_BINARY,
                $this->consolePath,
                'gar:import:worker',
                (string) $operationId,
                $sourcePath,
                '--batch-size=' . $batchSize,
                '--no-interaction',
                '--no-ansi',
            ],
            $descriptors,
            $pipes,
            dirname($this->consolePath, 2),
            $this->environment(),
        );
        if (!is_resource($process)) {
            throw new GarImportFailed('Unable to start a GAR import worker process.');
        }
        if (
            !isset($pipes[0], $pipes[1], $pipes[2])
            || !is_resource($pipes[0])
            || !is_resource($pipes[1])
            || !is_resource($pipes[2])
        ) {
            proc_terminate($process);
            proc_close($process);
            throw new GarImportFailed('Unable to open GAR import worker process pipes.');
        }

        try {
            $this->writePayload($pipes[0], $payload);
        } catch (Throwable $error) {
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_terminate($process);
            proc_close($process);
            throw $error;
        }
        fclose($pipes[0]);

        return new GarImportWorkerProcess(
            process: $process,
            standardOutput: $pipes[1],
            standardError: $pipes[2],
            description: sprintf(
                'worker[%s%s]',
                $files[0]->relativePath,
                count($files) > 1 ? sprintf(' +%d files', count($files) - 1) : '',
            ),
        );
    }

    /** @param resource $stream */
    private function writePayload($stream, string $payload): void
    {
        $offset = 0;
        $length = strlen($payload);
        while ($offset < $length) {
            $written = fwrite($stream, substr($payload, $offset));
            if (!is_int($written) || $written < 1) {
                throw new GarImportFailed('Unable to send a file assignment to a GAR import worker.');
            }
            $offset += $written;
        }
    }

    /** @return array<string, string> */
    private function environment(): array
    {
        $environment = getenv();
        $environment['DATABASE_DSN'] = $this->databaseDsn;

        return $environment;
    }
}
