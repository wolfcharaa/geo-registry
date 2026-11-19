<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use Amp\Cancellation;
use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use JsonException;
use Throwable;

/** Owns lifecycle and wire protocol for hidden GAR CLI worker processes. */
final readonly class GarImportWorkerProcessPool
{
    public function __construct(
        private string $databaseDsn,
        private string $consolePath,
    ) {}

    /**
     * @param non-empty-list<GarXmlSourceFile> $files
     * @throws JsonException
     */
    public function start(
        int $operationId,
        string $sourcePath,
        int $batchSize,
        bool $directCopy,
        array $files,
    ): GarImportWorkerProcess {
        $payload = \json_encode(\array_map(
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
        $arguments = [
            PHP_BINARY,
            $this->consolePath,
            'gar:import:worker',
            (string) $operationId,
            $sourcePath,
            '--batch-size=' . $batchSize,
            '--no-interaction',
            '--no-ansi',
        ];
        if ($directCopy) {
            $arguments[] = '--direct-copy';
        }
        $process = \proc_open(
            $arguments,
            $descriptors,
            $pipes,
            \dirname($this->consolePath, 2),
            $this->environment(),
        );
        if (!\is_resource($process)) {
            throw new GarImportFailed('Unable to start a GAR import worker process.');
        }
        if (
            !isset($pipes[0], $pipes[1], $pipes[2])
            || !\is_resource($pipes[0])
            || !\is_resource($pipes[1])
            || !\is_resource($pipes[2])
        ) {
            \proc_terminate($process);
            \proc_close($process);
            throw new GarImportFailed('Unable to open GAR import worker process pipes.');
        }

        try {
            self::writePayload($pipes[0], $payload);
        } catch (Throwable $error) {
            \fclose($pipes[0]);
            \fclose($pipes[1]);
            \fclose($pipes[2]);
            \proc_terminate($process);
            \proc_close($process);
            throw $error;
        }
        \fclose($pipes[0]);

        return new GarImportWorkerProcess(
            process: $process,
            standardOutput: $pipes[1],
            standardError: $pipes[2],
            description: \sprintf(
                'worker[%s%s]',
                $files[0]->relativePath,
                \count($files) > 1 ? \sprintf(' +%d files', \count($files) - 1) : '',
            ),
        );
    }

    /**
     * @param list<GarImportWorkerProcess> $processes
     * @return list<string>
     */
    public function poll(array &$processes): array
    {
        $failures = [];
        $running = [];
        foreach ($processes as $process) {
            $result = $process->poll();
            if ($result === null) {
                $running[] = $process;
                continue;
            }
            if ($result->exitCode === 0) {
                continue;
            }
            $details = $result->standardError !== ''
                ? $result->standardError
                : $result->standardOutput;
            $failures[] = \trim(\sprintf(
                '%s exited with code %d. %s',
                $result->description,
                $result->exitCode,
                $details,
            ));
        }
        $processes = $running;

        return $failures;
    }

    /** @param list<GarImportWorkerProcess> $processes */
    public function wait(array &$processes, Cancellation $cancellation): void
    {
        while ($processes !== []) {
            $cancellation->throwIfRequested();
            $failures = $this->poll($processes);
            if ($failures !== []) {
                throw new GarImportFailed('GAR import worker phase failed: ' . \implode(' | ', $failures));
            }
            if ($processes !== []) {
                \usleep(50_000);
            }
        }
    }

    /** @param list<GarImportWorkerProcess> $processes */
    public function terminate(array $processes): void
    {
        foreach ($processes as $process) {
            $process->terminate();
        }
    }

    /** @param resource $stream */
    private static function writePayload($stream, string $payload): void
    {
        $offset = 0;
        $length = \strlen($payload);
        while ($offset < $length) {
            $written = \fwrite($stream, \substr($payload, $offset));
            if (!\is_int($written) || $written < 1) {
                throw new GarImportFailed('Unable to send a file assignment to a GAR import worker.');
            }
            $offset += $written;
        }
    }

    /** @return array<string, string> */
    private function environment(): array
    {
        $environment = \getenv();
        $environment['DATABASE_DSN'] = $this->databaseDsn;

        return $environment;
    }
}
