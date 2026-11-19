<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use function Amp\async;

use Amp\Cancellation;
use Amp\DeferredCancellation;
use Amp\Future;
use Amp\Parallel\Context\ProcessContextFactory;
use Amp\Parallel\Worker\ContextWorkerFactory;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\Execution;
use Amp\Pipeline\Pipeline;
use Amp\TimeoutCancellation;
use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use Closure;
use Throwable;

final readonly class GarFusedRangeFileImporter
{
    private const CLEANUP_GRACE_SECONDS = 5.0;
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
        int $maxInFlightBytes,
        int $maxFragmentBytes,
        Closure $capacity,
    ): void {
        $this->importFiles(
            operationId: $operationId,
            files: [$file],
            batchSize: $batchSize,
            maxParsers: $maxParsers,
            maxWriters: $maxWriters,
            maxFiles: 1,
            maxInFlightBytes: $maxInFlightBytes,
            maxFragmentBytes: $maxFragmentBytes,
            capacity: $capacity,
        );
    }

    /**
     * @param list<GarXmlSourceFile> $files
     * @param Closure(): positive-int $capacity
     */
    public function importFiles(
        int $operationId,
        array $files,
        int $batchSize,
        int $maxParsers,
        int $maxWriters,
        int $maxFiles,
        int $maxInFlightBytes,
        int $maxFragmentBytes,
        Closure $capacity,
    ): void {
        if ($files === [] || $maxFiles < 1) {
            throw new GarImportFailed('GAR fused range phase requires XML files and positive concurrency.');
        }

        $pool = new ContextWorkerPool(
            $maxParsers,
            new ContextWorkerFactory(
                contextFactory: new ProcessContextFactory(workingDirectory: \dirname(__DIR__, 5)),
            ),
        );
        $taskAdmission = new GarFusedRangeTaskAdmission($maxParsers);
        $admission = new GarFusedRangeWriterAdmission($maxWriters, $maxInFlightBytes);
        $stop = new DeferredCancellation();
        $failure = null;
        $fail = static function (Throwable $error) use (&$failure, $stop): void {
            $failure ??= $error;
            $stop->cancel($failure);
        };

        try {
            Pipeline::fromIterable($files)
                ->concurrent(\min($maxFiles, \count($files)))
                ->unordered()
                ->map(function (GarXmlSourceFile $file) use (
                    $operationId,
                    $batchSize,
                    $maxParsers,
                    $maxFragmentBytes,
                    $capacity,
                    $pool,
                    $taskAdmission,
                    $admission,
                    $stop,
                    $fail,
                ): null {
                    try {
                        $this->importFile(
                            operationId: $operationId,
                            file: $file,
                            batchSize: $batchSize,
                            maxParsers: $maxParsers,
                            maxFragmentBytes: $maxFragmentBytes,
                            capacity: $capacity,
                            pool: $pool,
                            taskAdmission: $taskAdmission,
                            admission: $admission,
                            stop: $stop,
                            fail: $fail,
                        );
                    } catch (Throwable $error) {
                        $fail($error);
                        // The shared failure is rethrown after every file has completed cleanup.
                    }

                    return null;
                })
                ->count();

            if ($failure instanceof Throwable) {
                throw $failure;
            }

            $pool->shutdown();
        } catch (Throwable $error) {
            $fail($error);
            if ($pool->isRunning()) {
                $pool->kill();
            }
            throw $failure ?? $error;
        } finally {
            if ($pool->isRunning()) {
                $pool->kill();
            }
        }
    }

    /**
     * @param Closure(): positive-int $capacity
     * @param Closure(Throwable): void $fail
     */
    private function importFile(
        int $operationId,
        GarXmlSourceFile $file,
        int $batchSize,
        int $maxParsers,
        int $maxFragmentBytes,
        Closure $capacity,
        ContextWorkerPool $pool,
        GarFusedRangeTaskAdmission $taskAdmission,
        GarFusedRangeWriterAdmission $admission,
        DeferredCancellation $stop,
        Closure $fail,
    ): void {
        $connection = $this->connectionFactory->connect();
        $store = new PostgresGarImportStore($connection);
        $profile = GarFusedRangeProfileRecorder::fromEnvironment($connection, $operationId, $file);
        /** @var array<int, Execution<GarDirectCopyBatchResult, bool, GarDirectRangeReady>> $executions */
        $executions = [];
        /** @var array<int, Future<GarDirectRangeReady>> $readiness */
        $readiness = [];
        /** @var array<int, GarDirectRangeReady> $ready */
        $ready = [];
        /** @var array<int, string> $writerTokens */
        $writerTokens = [];
        /** @var array<int, string> $taskTokens */
        $taskTokens = [];

        try {
            $state = $store->fileState($operationId, $file->key);
            if ($state->completed) {
                return;
            }
            $store->startFile($operationId, $file->key);
            $committedRanges = $store->committedRanges($operationId, $file->key);

            foreach (
                $this->ranges->ranges(
                    $file,
                    $batchSize,
                    $state->processedRows,
                    $committedRanges,
                    $maxFragmentBytes,
                ) as $range
            ) {
                $stop->getCancellation()->throwIfRequested();
                $this->dispatchReady(
                    $store,
                    $file,
                    $executions,
                    $ready,
                    $writerTokens,
                    $admission,
                    $operationId,
                );
                $taskToken = $file->key . ':' . $range->firstRow;
                while (
                    \count($executions) >= $maxParsers
                    || \count($executions) >= $capacity()
                    || !$taskAdmission->tryAcquire($taskToken)
                ) {
                    if (!$this->awaitOne(
                        $executions,
                        $readiness,
                        $ready,
                        $writerTokens,
                        $taskTokens,
                        $admission,
                        $taskAdmission,
                        $profile,
                    )) {
                        $taskAdmission->awaitAvailability($stop->getCancellation());
                    }
                    $this->dispatchReady(
                        $store,
                        $file,
                        $executions,
                        $ready,
                        $writerTokens,
                        $admission,
                        $operationId,
                    );
                }

                /** @var Execution<GarDirectCopyBatchResult, bool, GarDirectRangeReady> $execution */
                $firstRow = $range->firstRow;
                try {
                    $execution = $pool->submit(
                        new GarParseAndCopyRangeTask(
                            $this->databaseDsn,
                            $operationId,
                            $file,
                            $range,
                            $maxFragmentBytes,
                        ),
                        $stop->getCancellation(),
                    );
                } catch (Throwable $error) {
                    $taskAdmission->release($taskToken);
                    throw $error;
                }
                $executions[$firstRow] = $execution;
                $taskTokens[$firstRow] = $taskToken;
                $readiness[$firstRow] = async(static function () use ($execution, $stop): GarDirectRangeReady {
                    return self::readiness($execution->getChannel()->receive($stop->getCancellation()));
                });
            }

            while ($executions !== []) {
                $this->dispatchReady(
                    $store,
                    $file,
                    $executions,
                    $ready,
                    $writerTokens,
                    $admission,
                    $operationId,
                );
                if (!$this->awaitOne(
                    $executions,
                    $readiness,
                    $ready,
                    $writerTokens,
                    $taskTokens,
                    $admission,
                    $taskAdmission,
                    $profile,
                )) {
                    $admission->awaitAvailability($stop->getCancellation());
                }
            }

            $profile?->flush();
            $store->completePipelineFile($operationId, $file->key);
        } catch (Throwable $error) {
            $fail($error);
            foreach ($readiness as $future) {
                $future->ignore();
            }
            try {
                $cleanup = new TimeoutCancellation(self::CLEANUP_GRACE_SECONDS);
                while ($executions !== []) {
                    $this->awaitExecution(
                        $executions,
                        $writerTokens,
                        $taskTokens,
                        $admission,
                        $taskAdmission,
                        $cleanup,
                    );
                }
            } catch (Throwable) {
                // Durable batch ranges define any COPY that raced with cleanup.
            }
            $store->failFile($operationId, $file->key, $error);
            throw $error;
        } finally {
            foreach ($writerTokens as $token) {
                $admission->release($token);
            }
            foreach ($taskTokens as $token) {
                $taskAdmission->release($token);
            }
            \pg_close($connection);
        }
    }

    /**
     * @param array<int, Execution<GarDirectCopyBatchResult, bool, GarDirectRangeReady>> $executions
     * @param array<int, GarDirectRangeReady> $ready
     * @param array<int, string> $writerTokens
     */
    private function dispatchReady(
        PostgresGarImportStore $store,
        GarXmlSourceFile $file,
        array $executions,
        array &$ready,
        array &$writerTokens,
        GarFusedRangeWriterAdmission $admission,
        int $operationId,
    ): void {
        \ksort($ready);
        while ($ready !== []) {
            $firstRow = \array_key_first($ready);
            $range = $ready[$firstRow];
            $token = $file->key . ':' . $firstRow;
            if (!$admission->tryAcquire($token, $range->encodedBytes)) {
                return;
            }

            $execution = $executions[$firstRow] ?? null;
            if (!$execution instanceof Execution) {
                $admission->release($token);
                throw new GarImportFailed('GAR direct range execution disappeared before COPY admission.');
            }
            try {
                $execution->getChannel()->send(true);
            } catch (Throwable $error) {
                $admission->release($token);
                throw $error;
            }
            $writerTokens[$firstRow] = $token;
            unset($ready[$firstRow]);
            $store->recordPipelinePeak($operationId, $admission->writers(), $admission->bytes());
        }
    }

    /**
     * @param array<int, Execution<GarDirectCopyBatchResult, bool, GarDirectRangeReady>> $executions
     * @param array<int, Future<GarDirectRangeReady>> $readiness
     * @param array<int, GarDirectRangeReady> $ready
     * @param array<int, string> $writerTokens
     * @param array<int, string> $taskTokens
     */
    private function awaitOne(
        array &$executions,
        array &$readiness,
        array &$ready,
        array &$writerTokens,
        array &$taskTokens,
        GarFusedRangeWriterAdmission $admission,
        GarFusedRangeTaskAdmission $taskAdmission,
        ?GarFusedRangeProfileRecorder $profile,
    ): bool {
        /** @var array<string, Future<mixed>> $futures */
        $futures = [];
        foreach ($readiness as $firstRow => $future) {
            $futures['ready:' . $firstRow] = $future;
        }
        foreach ($writerTokens as $firstRow => $_) {
            $execution = $executions[$firstRow] ?? null;
            if (!$execution instanceof Execution) {
                throw new GarImportFailed('GAR direct range writer execution disappeared before completion.');
            }
            $futures['writer:' . $firstRow] = $execution->getFuture();
        }

        foreach (Future::iterate($futures) as $key => $future) {
            $key = (string) $key;
            $separator = \strpos($key, ':');
            if ($separator === false) {
                throw new GarImportFailed('GAR direct range task returned an invalid event key.');
            }
            $type = \substr($key, 0, $separator);
            $firstRow = (int) \substr($key, $separator + 1);
            if ($type === 'ready') {
                try {
                    $message = $future->await();
                } catch (Throwable $receiveError) {
                    unset($readiness[$firstRow]);
                    $execution = $executions[$firstRow] ?? null;
                    if (!$execution instanceof Execution) {
                        throw new GarImportFailed(
                            'GAR direct range parser execution disappeared after channel failure.',
                            previous: $receiveError,
                        );
                    }
                    try {
                        $execution->getFuture()->await();
                    } catch (Throwable $taskError) {
                        $taskToken = $taskTokens[$firstRow] ?? null;
                        if (\is_string($taskToken)) {
                            $taskAdmission->release($taskToken);
                        }
                        unset($taskTokens[$firstRow]);
                        unset($executions[$firstRow]);
                        throw $taskError;
                    }
                    throw $receiveError;
                }
                unset($readiness[$firstRow]);
                if (!$message instanceof GarDirectRangeReady || $message->firstRow !== $firstRow) {
                    throw new GarImportFailed('GAR direct range readiness does not match its planned range.');
                }
                $ready[$firstRow] = $message;
                return true;
            }
            if ($type !== 'writer') {
                throw new GarImportFailed('GAR direct range task returned an invalid event type.');
            }

            try {
                $result = $future->await();
            } finally {
                $token = $writerTokens[$firstRow] ?? null;
                $taskToken = $taskTokens[$firstRow] ?? null;
                unset($executions[$firstRow], $writerTokens[$firstRow], $taskTokens[$firstRow]);
                if (\is_string($token)) {
                    $admission->release($token);
                }
                if (\is_string($taskToken)) {
                    $taskAdmission->release($taskToken);
                }
            }
            if (!$result instanceof GarDirectCopyBatchResult || $result->firstRow !== $firstRow) {
                throw new GarImportFailed('GAR direct range worker returned an invalid COPY result.');
            }
            $profile?->record($result);
            return true;
        }

        return false;
    }

    /**
     * @param array<int, Execution<GarDirectCopyBatchResult, bool, GarDirectRangeReady>> $executions
     * @param array<int, string> $writerTokens
     * @param array<int, string> $taskTokens
     */
    private function awaitExecution(
        array &$executions,
        array &$writerTokens,
        array &$taskTokens,
        GarFusedRangeWriterAdmission $admission,
        GarFusedRangeTaskAdmission $taskAdmission,
        Cancellation $cancellation,
    ): void {
        $futures = \array_map(
            static fn(Execution $execution): Future => $execution->getFuture(),
            $executions,
        );
        foreach (Future::iterate($futures, $cancellation) as $firstRow => $future) {
            try {
                $future->await($cancellation);
            } finally {
                $token = $writerTokens[$firstRow] ?? null;
                $taskToken = $taskTokens[$firstRow] ?? null;
                unset($executions[$firstRow], $writerTokens[$firstRow], $taskTokens[$firstRow]);
                if (\is_string($token)) {
                    $admission->release($token);
                }
                if (\is_string($taskToken)) {
                    $taskAdmission->release($taskToken);
                }
            }
            return;
        }

        throw new GarImportFailed('GAR direct range pipeline has no execution to clean up.');
    }

    private static function readiness(mixed $message): GarDirectRangeReady
    {
        if (!$message instanceof GarDirectRangeReady) {
            throw new GarImportFailed('GAR direct range worker returned invalid readiness metadata.');
        }

        return $message;
    }
}
