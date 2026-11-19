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

final readonly class GarParallelRangeFileImporter
{
    private const CLEANUP_GRACE_SECONDS = 5.0;
    private const MAX_IN_FLIGHT_ENCODED_BYTES = 16 * 1024 * 1024;

    public function __construct(
        private string $databaseDsn,
        private GarImportConnectionFactory $connectionFactory,
        private GarXmlByteRangeReader $ranges,
    ) {}

    /** @param Closure(): positive-int $capacity */
    public function import(
        int $operationId,
        GarXmlSourceFile $file,
        int $batchSize,
        int $maxParsers,
        int $maxWriters,
        Closure $capacity,
    ): void {
        $connection = $this->connectionFactory->connect();
        $store = new PostgresGarImportStore($connection);
        $workingDirectory = \dirname(__DIR__, 5);
        $parserPool = $this->pool($maxParsers, $workingDirectory);
        $writerPool = $this->pool($maxWriters, $workingDirectory);
        $stop = new DeferredCancellation();
        /** @var array<int, Execution<GarParsedXmlRange, never, never>> $parsers */
        $parsers = [];
        /** @var array<int, GarParsedXmlRange> $parsed */
        $parsed = [];
        /** @var array<int, Execution<GarDirectCopyBatchResult, never, never>> $writers */
        $writers = [];
        /** @var array<int, int> $writerBytes */
        $writerBytes = [];

        try {
            $state = $store->fileState($operationId, $file->key);
            if ($state->completed) {
                $parserPool->shutdown();
                $writerPool->shutdown();
                return;
            }
            $store->startFile($operationId, $file->key);
            $committedRanges = $store->committedRanges($operationId, $file->key);

            foreach ($this->ranges->ranges($file, $batchSize, $state->processedRows, $committedRanges) as $range) {
                $this->dispatchParsed(
                    $store,
                    $writerPool,
                    $stop,
                    $parsed,
                    $parsers,
                    $writers,
                    $writerBytes,
                    $capacity,
                    $maxWriters,
                    $operationId,
                    $file,
                );
                while (
                    \count($parsers) >= $maxParsers
                    || \count($parsers) + \count($writers) >= $capacity()
                    || \count($parsed) >= $maxParsers
                ) {
                    $this->awaitOne($parsers, $parsed, $writers, $writerBytes);
                    $this->dispatchParsed(
                        $store,
                        $writerPool,
                        $stop,
                        $parsed,
                        $parsers,
                        $writers,
                        $writerBytes,
                        $capacity,
                        $maxWriters,
                        $operationId,
                        $file,
                    );
                }
                $parsers[$range->firstRow] = $parserPool->submit(
                    new GarParseXmlRangeTask($file, $range),
                    $stop->getCancellation(),
                );
            }

            while ($parsers !== [] || $parsed !== [] || $writers !== []) {
                $this->dispatchParsed(
                    $store,
                    $writerPool,
                    $stop,
                    $parsed,
                    $parsers,
                    $writers,
                    $writerBytes,
                    $capacity,
                    $maxWriters,
                    $operationId,
                    $file,
                );
                if ($parsers !== [] || $writers !== []) {
                    $this->awaitOne($parsers, $parsed, $writers, $writerBytes);
                    continue;
                }
                if ($parsed !== []) {
                    throw new GarImportFailed('GAR parsed ranges cannot enter the COPY worker pool.');
                }
            }

            $parserPool->shutdown();
            $writerPool->shutdown();
            $store->completePipelineFile($operationId, $file->key);
        } catch (Throwable $error) {
            $stop->cancel($error);
            try {
                $cleanup = new TimeoutCancellation(self::CLEANUP_GRACE_SECONDS);
                while ($parsers !== [] || $writers !== []) {
                    $this->awaitOne($parsers, $parsed, $writers, $writerBytes, $cleanup);
                }
            } catch (Throwable) {
                // Durable batch ranges define any COPY that raced with cleanup.
            }
            $parserPool->kill();
            $writerPool->kill();
            $store->failFile($operationId, $file->key, $error);
            throw $error;
        } finally {
            if ($parserPool->isRunning()) {
                $parserPool->kill();
            }
            if ($writerPool->isRunning()) {
                $writerPool->kill();
            }
            \pg_close($connection);
        }
    }

    private function pool(int $workers, string $workingDirectory): ContextWorkerPool
    {
        return new ContextWorkerPool(
            $workers,
            new ContextWorkerFactory(
                contextFactory: new ProcessContextFactory(workingDirectory: $workingDirectory),
            ),
        );
    }

    /**
     * @param array<int, GarParsedXmlRange> $parsed
     * @param array<int, Execution<GarParsedXmlRange, never, never>> $parsers
     * @param array<int, Execution<GarDirectCopyBatchResult, never, never>> $writers
     * @param array<int, int> $writerBytes
     * @param Closure(): positive-int $capacity
     */
    private function dispatchParsed(
        PostgresGarImportStore $store,
        ContextWorkerPool $writerPool,
        DeferredCancellation $stop,
        array &$parsed,
        array $parsers,
        array &$writers,
        array &$writerBytes,
        Closure $capacity,
        int $maxWriters,
        int $operationId,
        GarXmlSourceFile $file,
    ): void {
        \ksort($parsed);
        while ($parsed !== []) {
            $firstRow = \array_key_first($parsed);
            $range = $parsed[$firstRow];
            if (
                \count($writers) >= $maxWriters
                || \count($parsers) + \count($writers) >= $capacity()
                || (
                    $writers !== []
                    && \array_sum($writerBytes) + $range->encodedBytes > self::MAX_IN_FLIGHT_ENCODED_BYTES
                )
            ) {
                return;
            }

            $task = new GarDirectCopyBatchTask(
                databaseDsn: $this->databaseDsn,
                operationId: $operationId,
                fileKey: $file->key,
                copyTarget: $file->descriptor->directCopyTarget(),
                rows: $range->rows,
                firstRow: $range->firstRow,
                lastRow: $range->lastRow,
            );
            /** @var Execution<GarDirectCopyBatchResult, never, never> $execution */
            $execution = $writerPool->submit($task, $stop->getCancellation());
            $writers[$firstRow] = $execution;
            $writerBytes[$firstRow] = $task->encodedBytes;
            unset($parsed[$firstRow]);
            $store->recordPipelinePeak($operationId, \count($writers), \array_sum($writerBytes));
        }
    }

    /**
     * @param array<int, Execution<GarParsedXmlRange, never, never>> $parsers
     * @param array<int, GarParsedXmlRange> $parsed
     * @param array<int, Execution<GarDirectCopyBatchResult, never, never>> $writers
     * @param array<int, int> $writerBytes
     */
    private function awaitOne(
        array &$parsers,
        array &$parsed,
        array &$writers,
        array &$writerBytes,
        ?Cancellation $cancellation = null,
    ): void {
        $futures = [];
        foreach ($parsers as $firstRow => $execution) {
            $futures['parser:' . $firstRow] = $execution->getFuture();
        }
        foreach ($writers as $firstRow => $execution) {
            $futures['writer:' . $firstRow] = $execution->getFuture();
        }

        foreach (Future::iterate($futures, $cancellation) as $key => $future) {
            $key = (string) $key;
            $separator = \strpos($key, ':');
            if ($separator === false) {
                throw new GarImportFailed('GAR parallel range task returned an invalid key.');
            }
            $type = \substr($key, 0, $separator);
            $firstRowValue = \substr($key, $separator + 1);
            $firstRow = (int) $firstRowValue;
            try {
                $result = $future->await($cancellation);
            } finally {
                if ($type === 'parser') {
                    unset($parsers[$firstRow]);
                } else {
                    unset($writers[$firstRow], $writerBytes[$firstRow]);
                }
            }
            if ($type === 'parser') {
                if (!$result instanceof GarParsedXmlRange || $result->firstRow !== $firstRow) {
                    throw new GarImportFailed('GAR parser worker returned an invalid range.');
                }
                $parsed[$firstRow] = $result;
                return;
            }
            if ($type !== 'writer') {
                throw new GarImportFailed('GAR parallel range task returned an invalid type.');
            }
            if (!$result instanceof GarDirectCopyBatchResult || $result->firstRow !== $firstRow) {
                throw new GarImportFailed('GAR COPY worker returned an invalid batch result.');
            }
            return;
        }

        throw new GarImportFailed('GAR parallel range pipeline has no task to await.');
    }
}
