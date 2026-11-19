<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use Amp\Cancellation;
use Amp\DeferredCancellation;
use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use Closure;
use Throwable;

final readonly class GarImportWorkerPool
{
    public function __construct(
        private GarImportWorkerPlan $plan,
        private GarImportPipelinePlan $pipelinePlan,
        private GarFilePhaseImporter $fileImporter,
        private GarImportWorkerProcessPool $processPool,
        private GarParallelFileImporter $parallelFileImporter,
        private GarParallelRangeFileImporter $parallelRangeFileImporter,
        private GarFusedRangeFileImporter $fusedRangeFileImporter,
    ) {}

    /**
     * @param list<GarXmlSourceFile> $pipelineFiles
     * @param list<GarXmlSourceFile> $files
     */
    public function run(
        int $operationId,
        string $sourcePath,
        int $batchSize,
        int $workerCount,
        bool $directCopy,
        array $pipelineFiles,
        ?int $pipelineWriters,
        ?int $pipelineParsers,
        bool $pipelineFusedRanges,
        int $pipelineFileConcurrency,
        int $pipelineBufferMiB,
        int $pipelineFragmentMiB,
        bool $pipelineTableAwareFiles,
        array $files,
    ): void {
        $interruptedSignal = null;
        $stop = new DeferredCancellation();
        $previousAsyncSignals = \pcntl_async_signals(true);
        $previousInterruptHandler = \pcntl_signal_get_handler(SIGINT);
        $previousTerminateHandler = \pcntl_signal_get_handler(SIGTERM);
        $interrupt = static function (int $signal) use (&$interruptedSignal, $stop): void {
            $interruptedSignal = $signal;
            if (!$stop->isCancelled()) {
                $stop->cancel(new GarImportFailed("GAR import worker pool was interrupted by signal {$signal}."));
            }
        };
        \pcntl_signal(SIGINT, $interrupt);
        \pcntl_signal(SIGTERM, $interrupt);

        try {
            foreach ($this->plan->phases($files, $workerCount) as $assignments) {
                if ($interruptedSignal !== null) {
                    throw $this->interrupted($interruptedSignal);
                }
                $phasePipelineFiles = $this->pipelinePlan->filesForPhase($assignments, $pipelineFiles);
                if ($phasePipelineFiles !== []) {
                    if ($pipelineWriters === null) {
                        throw new GarImportFailed('GAR pipeline writer count was not configured.');
                    }
                    $this->runPipelinePhase(
                        operationId: $operationId,
                        sourcePath: $sourcePath,
                        batchSize: $batchSize,
                        directCopy: $directCopy,
                        workerCount: $workerCount,
                        pipelineFiles: $phasePipelineFiles,
                        pipelineWriters: $pipelineWriters,
                        pipelineParsers: $pipelineParsers,
                        pipelineFusedRanges: $pipelineFusedRanges,
                        pipelineFileConcurrency: $pipelineFileConcurrency,
                        pipelineBufferMiB: $pipelineBufferMiB,
                        pipelineFragmentMiB: $pipelineFragmentMiB,
                        pipelineTableAwareFiles: $pipelineTableAwareFiles,
                        assignments: $assignments,
                        cancellation: $stop->getCancellation(),
                    );
                    continue;
                }
                $this->runPhase(
                    $operationId,
                    $sourcePath,
                    $batchSize,
                    $directCopy,
                    $assignments,
                    $stop->getCancellation(),
                );
            }
        } finally {
            \pcntl_signal(SIGINT, $previousInterruptHandler);
            \pcntl_signal(SIGTERM, $previousTerminateHandler);
            \pcntl_async_signals($previousAsyncSignals);
        }
    }

    /**
     * @param list<GarXmlSourceFile> $pipelineFiles
     * @param list<non-empty-list<GarXmlSourceFile>> $assignments
     */
    private function runPipelinePhase(
        int $operationId,
        string $sourcePath,
        int $batchSize,
        bool $directCopy,
        int $workerCount,
        array $pipelineFiles,
        int $pipelineWriters,
        ?int $pipelineParsers,
        bool $pipelineFusedRanges,
        int $pipelineFileConcurrency,
        int $pipelineBufferMiB,
        int $pipelineFragmentMiB,
        bool $pipelineTableAwareFiles,
        array $assignments,
        Cancellation $cancellation,
    ): void {
        $remainingFiles = $this->pipelinePlan->remainingFiles($assignments, $pipelineFiles);
        $ordinaryAssignments = $this->pipelinePlan->concurrentAssignments($remainingFiles, $workerCount);

        /** @var list<GarImportWorkerProcess> $processes */
        $processes = [];
        try {
            foreach ($ordinaryAssignments as $files) {
                $cancellation->throwIfRequested();
                $processes[] = $this->processPool->start(
                    $operationId,
                    $sourcePath,
                    $batchSize,
                    $directCopy,
                    $files,
                );
            }
            /** @var Closure(): positive-int $capacity */
            $capacity = function () use (
                &$processes,
                $cancellation,
                $workerCount,
                $pipelineWriters,
                $pipelineParsers,
            ): int {
                $cancellation->throwIfRequested();
                $failures = $this->processPool->poll($processes);
                if ($failures !== []) {
                    throw new GarImportFailed('GAR import worker phase failed: ' . \implode(' | ', $failures));
                }

                $pipelineWorkers = $pipelineWriters + ($pipelineParsers ?? 0);

                return $this->pipelinePlan->capacity($workerCount, $pipelineWorkers, \count($processes));
            };
            if (
                $pipelineFusedRanges
                && $pipelineParsers !== null
                && $pipelineFileConcurrency > 1
                && \count($pipelineFiles) > 1
                && $remainingFiles === []
            ) {
                $fileGroups = $pipelineTableAwareFiles
                    ? $this->pipelinePlan->tableGroups($pipelineFiles)
                    : [$pipelineFiles];
                foreach ($fileGroups as $fileGroup) {
                    $this->fusedRangeFileImporter->importFiles(
                        operationId: $operationId,
                        files: $fileGroup,
                        batchSize: $batchSize,
                        maxParsers: $pipelineParsers,
                        maxWriters: $pipelineWriters,
                        maxFiles: $pipelineFileConcurrency,
                        maxInFlightBytes: $pipelineBufferMiB * 1024 * 1024,
                        maxFragmentBytes: $pipelineFragmentMiB * 1024 * 1024,
                        capacity: $capacity,
                    );
                }
            } else {
                foreach ($pipelineFiles as $pipelineFile) {
                    $cancellation->throwIfRequested();
                    if ($pipelineParsers === null) {
                        $this->parallelFileImporter->import(
                            operationId: $operationId,
                            file: $pipelineFile,
                            batchSize: $batchSize,
                            maxWriters: $pipelineWriters,
                            capacity: $capacity,
                        );
                    } elseif ($pipelineFusedRanges) {
                        $this->fusedRangeFileImporter->import(
                            operationId: $operationId,
                            file: $pipelineFile,
                            batchSize: $batchSize,
                            maxParsers: $pipelineParsers,
                            maxWriters: $pipelineWriters,
                            maxInFlightBytes: $pipelineBufferMiB * 1024 * 1024,
                            maxFragmentBytes: $pipelineFragmentMiB * 1024 * 1024,
                            capacity: $capacity,
                        );
                    } else {
                        $this->parallelRangeFileImporter->import(
                            operationId: $operationId,
                            file: $pipelineFile,
                            batchSize: $batchSize,
                            maxParsers: $pipelineParsers,
                            maxWriters: $pipelineWriters,
                            capacity: $capacity,
                        );
                    }
                }
            }
            $this->processPool->wait($processes, $cancellation);
            if ($ordinaryAssignments === [] && $remainingFiles !== []) {
                $phases = $this->plan->phases($remainingFiles, $workerCount);
                foreach ($phases as $deferredAssignments) {
                    $this->runPhase(
                        $operationId,
                        $sourcePath,
                        $batchSize,
                        $directCopy,
                        $deferredAssignments,
                        $cancellation,
                    );
                }
            }
        } catch (Throwable $error) {
            $this->processPool->terminate($processes);
            throw $error;
        }
    }

    /** @param list<non-empty-list<GarXmlSourceFile>> $assignments */
    private function runPhase(
        int $operationId,
        string $sourcePath,
        int $batchSize,
        bool $directCopy,
        array $assignments,
        Cancellation $cancellation,
    ): void {
        $this->fileImporter->import(
            operationId: $operationId,
            sourcePath: $sourcePath,
            batchSize: $batchSize,
            directCopy: $directCopy,
            assignments: $assignments,
            cancellation: $cancellation,
        );
    }

    private function interrupted(int $signal): GarImportFailed
    {
        return new GarImportFailed("GAR import worker pool was interrupted by signal {$signal}.");
    }
}
