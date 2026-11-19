<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use Amp\Cancellation;
use Amp\DeferredCancellation;
use Amp\Future;
use Amp\Parallel\Context\ProcessContextFactory;
use Amp\Parallel\Worker\ContextWorkerFactory;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\Execution;
use Amp\TimeoutCancellation;
use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use Closure;
use Throwable;

final readonly class GarParallelFileImporter
{
    private const CLEANUP_GRACE_SECONDS = 5.0;
    private const MAX_BATCH_ENCODED_BYTES = 8 * 1024 * 1024;
    private const MAX_IN_FLIGHT_ENCODED_BYTES = 16 * 1024 * 1024;

    public function __construct(
        private string $databaseDsn,
        private GarImportConnectionFactory $connectionFactory,
        private GarXmlRowReader $reader,
        private GarCopyRowEncoder $encoder,
    ) {}

    /**
     * @param Closure(): positive-int $capacity
     */
    public function import(
        int $operationId,
        GarXmlSourceFile $file,
        int $batchSize,
        int $maxWriters,
        Closure $capacity,
    ): void {
        $connection = $this->connectionFactory->connect();
        $store = new PostgresGarImportStore($connection);
        $pool = new ContextWorkerPool(
            $maxWriters,
            new ContextWorkerFactory(
                contextFactory: new ProcessContextFactory(workingDirectory: \dirname(__DIR__, 5)),
            ),
        );
        $stop = new DeferredCancellation();
        /** @var array<int, Execution<GarDirectCopyBatchResult, never, never>> $executions */
        $executions = [];
        /** @var array<int, int> $inFlightBytes */
        $inFlightBytes = [];

        try {
            $state = $store->fileState($operationId, $file->key);
            if ($state->completed) {
                $pool->shutdown();
                return;
            }
            $store->startFile($operationId, $file->key);
            $committedRanges = $store->committedRanges($operationId, $file->key);
            $rangeIndex = 0;
            $batch = [];
            $batchBytes = 0;
            $batchFirstRow = null;
            $batchLastRow = null;

            foreach ($this->reader->rows($file, $state->processedRows) as $rowNumber => $row) {
                while (
                    isset($committedRanges[$rangeIndex])
                    && $rowNumber > $committedRanges[$rangeIndex]->lastRow
                ) {
                    ++$rangeIndex;
                }
                $committed = $committedRanges[$rangeIndex] ?? null;
                if (
                    $committed instanceof GarImportCommittedRange
                    && $rowNumber >= $committed->firstRow
                    && $rowNumber <= $committed->lastRow
                ) {
                    if ($batch !== []) {
                        $this->dispatch(
                            $store,
                            $pool,
                            $stop,
                            $executions,
                            $inFlightBytes,
                            $capacity,
                            $operationId,
                            $file,
                            $batch,
                            $batchFirstRow,
                            $batchLastRow,
                        );
                        $batch = [];
                        $batchBytes = 0;
                        $batchFirstRow = null;
                        $batchLastRow = null;
                    }
                    continue;
                }

                $encodedRow = $this->encoder->encode($row);
                $encodedRowBytes = \strlen($encodedRow);
                if ($batch !== [] && $batchBytes + $encodedRowBytes > self::MAX_BATCH_ENCODED_BYTES) {
                    $this->dispatch(
                        $store,
                        $pool,
                        $stop,
                        $executions,
                        $inFlightBytes,
                        $capacity,
                        $operationId,
                        $file,
                        $batch,
                        $batchFirstRow,
                        $batchLastRow,
                    );
                    $batch = [];
                    $batchBytes = 0;
                    $batchFirstRow = null;
                }
                $batchFirstRow ??= $rowNumber;
                $batchLastRow = $rowNumber;
                $batch[] = $encodedRow;
                $batchBytes += $encodedRowBytes;
                if (\count($batch) < $batchSize && $batchBytes < self::MAX_BATCH_ENCODED_BYTES) {
                    continue;
                }
                $this->dispatch(
                    $store,
                    $pool,
                    $stop,
                    $executions,
                    $inFlightBytes,
                    $capacity,
                    $operationId,
                    $file,
                    $batch,
                    $batchFirstRow,
                    $batchLastRow,
                );
                $batch = [];
                $batchBytes = 0;
                $batchFirstRow = null;
                $batchLastRow = null;
            }
            if ($batch !== []) {
                $this->dispatch(
                    $store,
                    $pool,
                    $stop,
                    $executions,
                    $inFlightBytes,
                    $capacity,
                    $operationId,
                    $file,
                    $batch,
                    $batchFirstRow,
                    $batchLastRow,
                );
            }
            while ($executions !== []) {
                $this->awaitOne($executions, $inFlightBytes);
            }
            $pool->shutdown();
            $store->completePipelineFile($operationId, $file->key);
        } catch (Throwable $error) {
            $stop->cancel($error);
            try {
                $cleanup = new TimeoutCancellation(self::CLEANUP_GRACE_SECONDS);
                while ($executions !== []) {
                    $this->awaitOne($executions, $inFlightBytes, $cleanup);
                }
            } catch (Throwable) {
                // Durable batch ranges define any COPY that raced with cleanup.
            }
            $pool->kill();
            $store->failFile($operationId, $file->key, $error);
            throw $error;
        } finally {
            if ($pool->isRunning()) {
                $pool->kill();
            }
            \pg_close($connection);
        }
    }

    /**
     * @param array<int, Execution<GarDirectCopyBatchResult, never, never>> $executions
     * @param array<int, int> $inFlightBytes
     * @param Closure(): positive-int $capacity
     * @param non-empty-list<string> $batch
     */
    private function dispatch(
        PostgresGarImportStore $store,
        ContextWorkerPool $pool,
        DeferredCancellation $stop,
        array &$executions,
        array &$inFlightBytes,
        Closure $capacity,
        int $operationId,
        GarXmlSourceFile $file,
        array $batch,
        ?int $firstRow,
        ?int $lastRow,
    ): void {
        if ($firstRow === null || $lastRow === null) {
            throw new GarImportFailed('GAR pipeline batch has no XML row boundary.');
        }
        $task = new GarDirectCopyBatchTask(
            databaseDsn: $this->databaseDsn,
            operationId: $operationId,
            fileKey: $file->key,
            copyTarget: $file->descriptor->directCopyTarget(),
            rows: $batch,
            firstRow: $firstRow,
            lastRow: $lastRow,
        );
        $allowed = $capacity();
        while (
            \count($executions) >= $allowed
            || (
                $executions !== []
                && \array_sum($inFlightBytes) + $task->encodedBytes > self::MAX_IN_FLIGHT_ENCODED_BYTES
            )
        ) {
            $this->awaitOne($executions, $inFlightBytes);
            $allowed = $capacity();
        }
        /** @var Execution<GarDirectCopyBatchResult, never, never> $execution */
        $execution = $pool->submit($task, $stop->getCancellation());
        $executions[$firstRow] = $execution;
        $inFlightBytes[$firstRow] = $task->encodedBytes;
        $store->recordPipelinePeak(
            $operationId,
            \count($executions),
            \array_sum($inFlightBytes),
        );
    }

    /**
     * @param array<int, Execution<GarDirectCopyBatchResult, never, never>> $executions
     * @param array<int, int> $inFlightBytes
     */
    private function awaitOne(
        array &$executions,
        array &$inFlightBytes,
        ?Cancellation $cancellation = null,
    ): GarDirectCopyBatchResult {
        $futures = \array_map(
            static fn(Execution $execution): Future => $execution->getFuture(),
            $executions,
        );
        foreach (Future::iterate($futures, $cancellation) as $firstRow => $future) {
            try {
                $result = $future->await($cancellation);
            } finally {
                unset($executions[$firstRow], $inFlightBytes[$firstRow]);
            }
            if ($result->firstRow !== $firstRow) {
                throw new GarImportFailed('GAR pipeline worker returned an invalid batch result.');
            }

            return $result;
        }

        throw new GarImportFailed('GAR pipeline has no in-flight batch to await.');
    }
}
