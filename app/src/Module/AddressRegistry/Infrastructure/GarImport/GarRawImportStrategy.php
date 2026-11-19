<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use Amp\Cancellation;
use Fedor\WorkOrchestrator\Contract\BootstrapsApplication;
use Fedor\WorkOrchestrator\Contract\ManagedTaskBootstrap;
use Fedor\WorkOrchestrator\Contract\ManagedTaskGroup;
use Fedor\WorkOrchestrator\Contract\ProvidesManagedTaskBootstrap;
use Fedor\WorkOrchestrator\Contract\UsesManagedTasks;
use Fedor\WorkOrchestrator\TaskHandle;
use Fedor\WorkOrchestrator\WorkEnvelope;
use InvalidArgumentException;
use LogicException;
use PgSql\Connection;
use Throwable;

/**
 * Executes ordinary files in primaries and fused ranges in managed children.
 *
 * @implements BootstrapsApplication<GarXmlSourceFile, GarRawFileResult>
 * @implements UsesManagedTasks<GarXmlSourceFile, GarRawFileResult>
 * @implements ProvidesManagedTaskBootstrap<GarXmlSourceFile, GarRawFileResult>
 */
final class GarRawImportStrategy implements
    BootstrapsApplication,
    UsesManagedTasks,
    ProvidesManagedTaskBootstrap
{
    private ?Connection $connection = null;

    private ?PostgresGarImportStore $store = null;

    private ?GarDirectCopyRowMapper $directCopyMapper = null;

    private ?GarXmlFileImporter $fileImporter = null;

    private ?GarXmlByteRangeReader $ranges = null;

    private ?ManagedTaskGroup $tasks = null;

    private bool $writeModeVerified = false;

    /** @var array<string, true> */
    private readonly array $pipelineFileKeys;

    /**
     * @param list<string> $pipelineFileKeys
     */
    public function __construct(
        private readonly string $databaseDsn,
        private readonly int $operationId,
        private readonly int $batchSize,
        array $pipelineFileKeys,
        private readonly int $maxInFlightRanges,
        private readonly int $maxFragmentBytes,
    ) {
        if (
            $this->operationId < 1
            || $this->batchSize < 1
            || $this->maxInFlightRanges < 1
            || $this->maxFragmentBytes < 8 * 1024 * 1024
            || $this->maxFragmentBytes > 16 * 1024 * 1024
        ) {
            throw new InvalidArgumentException('GAR raw import strategy received invalid execution limits.');
        }
        $this->pipelineFileKeys = \array_fill_keys($pipelineFileKeys, true);
        if (\count($this->pipelineFileKeys) !== \count($pipelineFileKeys)) {
            throw new InvalidArgumentException('GAR raw import strategy received duplicate pipeline file keys.');
        }
    }

    public function boot(Cancellation $cancellation): void
    {
        $cancellation->throwIfRequested();
        if ($this->connection !== null) {
            throw new LogicException('GAR raw import strategy is already bootstrapped.');
        }

        $connection = new GarImportConnectionFactory($this->databaseDsn)->connect();

        try {
            $this->store = new PostgresGarImportStore($connection);
            $this->directCopyMapper = new GarDirectCopyRowMapper($connection);
            $this->fileImporter = new GarXmlFileImporter(
                new GarXmlRowReader(),
                new GarCopyRowEncoder(),
            );
            $this->ranges = new GarXmlByteRangeReader(new GarXmlStructureInspector());
            $this->connection = $connection;
        } catch (Throwable $failure) {
            \pg_close($connection);
            throw $failure;
        }
    }

    public function useManagedTasks(ManagedTaskGroup $tasks): void
    {
        $this->tasks = $tasks;
    }

    public function managedTaskBootstrap(): ManagedTaskBootstrap
    {
        return new GarRawRangeTaskBootstrap($this->databaseDsn);
    }

    /** @param WorkEnvelope<GarXmlSourceFile> $work */
    public function run(WorkEnvelope $work, Cancellation $cancellation): GarRawFileResult
    {
        $store = $this->store
            ?? throw new LogicException('GAR raw import store is not bootstrapped.');
        $file = $work->payload;
        if ($work->id->value !== $file->path) {
            throw new LogicException('GAR raw work identity does not match its manifest path.');
        }

        if (!$this->writeModeVerified) {
            $store->assertWorkerWriteMode($this->operationId, true);
            $this->writeModeVerified = true;
        }

        if (isset($this->pipelineFileKeys[$file->key])) {
            return $this->importRanges($file, $cancellation);
        }

        $directCopyMapper = $this->directCopyMapper
            ?? throw new LogicException('GAR raw direct-copy mapper is not bootstrapped.');
        $fileImporter = $this->fileImporter
            ?? throw new LogicException('GAR raw XML importer is not bootstrapped.');
        $fileKey = $fileImporter->import(
            store: $store,
            directCopyMapper: $directCopyMapper,
            operationId: $this->operationId,
            batchSize: $this->batchSize,
            directCopy: true,
            file: $file,
            cancellation: $cancellation,
        );

        return new GarRawFileResult($fileKey, 0, 0, 0);
    }

    public function close(): void
    {
        $connection = $this->connection;
        $this->connection = null;
        $this->store = null;
        $this->directCopyMapper = null;
        $this->fileImporter = null;
        $this->ranges = null;
        $this->tasks = null;
        $this->writeModeVerified = false;

        if ($connection !== null) {
            \pg_close($connection);
        }
    }

    private function importRanges(
        GarXmlSourceFile $file,
        Cancellation $cancellation,
    ): GarRawFileResult {
        $store = $this->store
            ?? throw new LogicException('GAR raw import store is not bootstrapped.');
        $ranges = $this->ranges
            ?? throw new LogicException('GAR raw range reader is not bootstrapped.');
        $tasks = $this->tasks
            ?? throw new LogicException('GAR raw managed task group was not provided.');
        $state = $store->fileState($this->operationId, $file->key);
        if ($state->completed) {
            return new GarRawFileResult($file->key, 0, 0, 0);
        }

        $store->startFile($this->operationId, $file->key);
        $profile = GarFusedRangeProfileRecorder::fromEnvironment(
            $this->connection
                ?? throw new LogicException('GAR raw import connection is not bootstrapped.'),
            $this->operationId,
            $file,
        );
        /**
         * @var list<array{
         *     handle: TaskHandle,
         *     firstRow: int,
         *     lastRow: int,
         *     requestBytes: int
         * }> $handles
         */
        $handles = [];
        $rangeTasks = 0;
        $requestBytes = 0;
        $resultBytes = 0;

        try {
            foreach ($ranges->ranges(
                $file,
                $this->batchSize,
                $state->processedRows,
                $store->committedRanges($this->operationId, $file->key),
                $this->maxFragmentBytes,
            ) as $range) {
                $cancellation->throwIfRequested();
                $task = new GarRawRangeTask(
                    operationId: $this->operationId,
                    file: $file,
                    range: $range,
                    maxEncodedBytes: $this->maxFragmentBytes,
                );
                $serializedRequestBytes = \strlen(\serialize($task));
                $requestBytes += $serializedRequestBytes;
                $handles[] = [
                    'handle' => $tasks->submit($task),
                    'firstRow' => $range->firstRow,
                    'lastRow' => $range->lastRow,
                    'requestBytes' => $serializedRequestBytes,
                ];
                ++$rangeTasks;
                $store->recordPipelinePeak(
                    $this->operationId,
                    \count($handles),
                    \array_sum(\array_column($handles, 'requestBytes')),
                );
                if (\count($handles) >= $this->maxInFlightRanges) {
                    $result = $this->awaitFirst($handles, $cancellation);
                    $resultBytes += \strlen(\serialize($result));
                    $profile?->record($result);
                }
            }

            while ($handles !== []) {
                $result = $this->awaitFirst($handles, $cancellation);
                $resultBytes += \strlen(\serialize($result));
                $profile?->record($result);
            }

            $profile?->flush();
            $store->completePipelineFile($this->operationId, $file->key);

            return new GarRawFileResult($file->key, $rangeTasks, $requestBytes, $resultBytes);
        } catch (Throwable $failure) {
            $store->failFile($this->operationId, $file->key, $failure);
            throw $failure;
        }
    }

    /**
     * @param list<array{handle: TaskHandle, firstRow: int, lastRow: int, requestBytes: int}> $handles
     */
    private function awaitFirst(array &$handles, Cancellation $cancellation): GarDirectCopyBatchResult
    {
        $entry = \array_shift($handles)
            ?? throw new LogicException('GAR raw range pipeline has no managed task to await.');
        $result = $entry['handle']->value($cancellation);
        if (!$result instanceof GarDirectCopyBatchResult
            || $result->firstRow !== $entry['firstRow']
            || $result->lastRow !== $entry['lastRow']) {
            throw new LogicException('GAR raw managed task returned an invalid range result.');
        }

        return $result;
    }
}
