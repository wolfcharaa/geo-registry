<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use Amp\DeferredCancellation;
use App\Module\AddressRegistry\Capability\ImportGarRaw\ImportGarRaw;
use App\Module\AddressRegistry\Capability\ImportGarRaw\ImportGarRawFailed;
use App\Module\AddressRegistry\Capability\ImportGarRaw\ImportGarRawPort;
use App\Module\AddressRegistry\Capability\ImportGarRaw\ImportGarRawResult;
use Fedor\WorkOrchestrator\Exception\RunFailed;
use Fedor\WorkOrchestrator\Orchestrator;
use Fedor\WorkOrchestrator\Result\FailureDiagnostic;
use Fedor\WorkOrchestrator\Result\FailureKind;
use Fedor\WorkOrchestrator\Result\RunResult;
use Throwable;

/** Experimental raw-only adapter; production ImportGarPort remains the control path. */
final readonly class GarWorkOrchestratedRawImportAdapter implements ImportGarRawPort
{
    public function __construct(
        private string $databaseDsn,
        private GarImportConnectionFactory $connectionFactory,
        private GarXmlCatalog $catalog,
        private GarImportPipelineSelector $pipelineSelector,
    ) {}

    public function import(ImportGarRaw $command): ImportGarRawResult
    {
        try {
            return $this->run($command);
        } catch (ImportGarRawFailed $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw self::reportedFailure($failure);
        }
    }

    private function run(ImportGarRaw $command): ImportGarRawResult
    {
        $manifest = $this->catalog->manifest($command->sourcePath, $command->regions);
        $sourcePath = $manifest->sourcePath;
        $sourceKey = \hash('sha256', $sourcePath);
        $pipelineFiles = $this->pipelineSelector->select($command->pipelineFile, $manifest->files);
        if ($pipelineFiles === []) {
            throw new ImportGarRawFailed('GAR raw work-orchestrator requires at least one fused pipeline file.');
        }
        $pipelineSelectionKey = $this->pipelineSelector->identity($pipelineFiles);
        $connection = $this->connectionFactory->connect();
        $store = new PostgresGarImportStore($connection);
        $initialLoad = new GarInitialLoadStore($connection);
        $stop = new DeferredCancellation();
        $databaseLockAcquired = false;
        $sourceLockAcquired = false;
        $operationId = null;
        $previousAsyncSignals = \pcntl_async_signals(true);
        $previousInterruptHandler = \pcntl_signal_get_handler(SIGINT);
        $previousTerminateHandler = \pcntl_signal_get_handler(SIGTERM);
        $interrupt = static function (int $signal) use ($stop): void {
            if (!$stop->isCancelled()) {
                $stop->cancel(new ImportGarRawFailed(
                    "GAR raw work-orchestrator run was interrupted by signal {$signal}.",
                ));
            }
        };
        \pcntl_signal(SIGINT, $interrupt);
        \pcntl_signal(SIGTERM, $interrupt);

        try {
            $store->acquireDatabaseLock(true);
            $databaseLockAcquired = true;
            $store->ensureNoInitialLoadRecoveryRequired(true);
            $store->acquireLock($sourceKey);
            $sourceLockAcquired = true;
            $operation = $store->startOperation(
                sourceKey: $sourceKey,
                sourcePath: $sourcePath,
                manifestHash: $manifest->hash,
                batchSize: $command->batchSize,
                restart: false,
                initialLoad: true,
                directCopy: true,
                pipelineFileKey: $pipelineSelectionKey,
                pipelineWriters: $command->childWorkersPerPrimary,
                deferPrimaryKeys: $command->deferPrimaryKeys,
                deferForeignKeys: $command->deferForeignKeys,
                pipelineFileKeys: \array_map(
                    static fn(GarXmlSourceFile $file): string => $file->key,
                    $pipelineFiles,
                ),
                files: $manifest->files,
            );
            $operationId = $operation->id;

            if (!$operation->alreadyCompleted) {
                $initialLoad->prepare($operation->id, $this->catalog->tables());
            }

            $pendingFiles = \array_values(\array_filter(
                $manifest->files,
                static fn(GarXmlSourceFile $file): bool => !$store
                    ->fileState($operation->id, $file->key)
                    ->completed,
            ));
            $rangeTasks = 0;
            $managedTaskRequestBytes = 0;
            $managedTaskResultBytes = 0;
            $rawSeconds = 0.0;
            $diagnostics = [];

            if ($pendingFiles !== []) {
                $startedAt = \hrtime(true);
                $run = Orchestrator::fromQueue(GarRawFileAdmissionQueue::create($pendingFiles))
                    ->primaryWorkers($command->primaryWorkers)
                    ->childWorkersPerPrimary($command->childWorkersPerPrimary)
                    ->run(
                        new GarRawImportStrategy(
                            databaseDsn: $this->databaseDsn,
                            operationId: $operation->id,
                            batchSize: $command->batchSize,
                            pipelineFileKeys: \array_map(
                                static fn(GarXmlSourceFile $file): string => $file->key,
                                $pipelineFiles,
                            ),
                            maxInFlightRanges: $command->childWorkersPerPrimary,
                            maxFragmentBytes: $command->fragmentMiB * 1024 * 1024,
                        ),
                        $stop->getCancellation(),
                    )
                    ->throwIfFailed();
                $rawSeconds = (\hrtime(true) - $startedAt) / 1_000_000_000;
                $diagnostics = \array_map(
                    GarRawDiagnosticMapper::map(...),
                    $run->diagnostics(),
                );

                $expectedFileKeys = \array_fill_keys(\array_map(
                    static fn(GarXmlSourceFile $file): string => $file->key,
                    $pendingFiles,
                ), true);
                foreach ($run->values() as $value) {
                    if (!isset($expectedFileKeys[$value->fileKey])) {
                        throw new ImportGarRawFailed(
                            'GAR raw work-orchestrator returned an invalid file result.',
                        );
                    }
                    unset($expectedFileKeys[$value->fileKey]);
                    $rangeTasks += $value->rangeTasks;
                    $managedTaskRequestBytes += $value->managedTaskRequestBytes;
                    $managedTaskResultBytes += $value->managedTaskResultBytes;
                }
                if ($expectedFileKeys !== []) {
                    throw new ImportGarRawFailed(
                        'GAR raw work-orchestrator did not return every pending manifest file.',
                    );
                }
            }

            $result = $store->result($operation->id, $operation->resumed, $operation->alreadyCompleted);
            if (
                $result->filesCompleted !== $result->filesTotal
                || $result->rowsProcessed !== $result->rowsMerged
            ) {
                throw new ImportGarRawFailed(
                    'GAR raw boundary requires every manifest file and copied row to be durably complete.',
                );
            }

            return new ImportGarRawResult(
                operationId: $result->operationId,
                filesTotal: $result->filesTotal,
                filesCompleted: $result->filesCompleted,
                rowsProcessed: $result->rowsProcessed,
                rowsMerged: $result->rowsMerged,
                resumed: $result->resumed,
                alreadyCompleted: $result->alreadyCompleted,
                durablePhase: $result->phase,
                pipelineFile: $result->pipelineFile,
                pipelineBatches: $result->pipelineBatches,
                pipelineBytes: $result->pipelineBytes,
                rangeTasks: $rangeTasks,
                managedTaskRequestBytes: $managedTaskRequestBytes,
                managedTaskResultBytes: $managedTaskResultBytes,
                indexesDeferred: $result->indexesTotal,
                foreignKeysDeferred: $result->foreignKeysTotal,
                deferPrimaryKeys: $result->deferPrimaryKeys,
                deferForeignKeys: $result->deferForeignKeys,
                rawSeconds: $rawSeconds,
                diagnostics: $diagnostics,
            );
        } catch (Throwable $failure) {
            $reportedFailure = self::reportedFailure($failure);
            if ($operationId !== null) {
                $store->failOperation($operationId, null, $reportedFailure);
            }

            throw $reportedFailure;
        } finally {
            \pcntl_signal(SIGINT, $previousInterruptHandler);
            \pcntl_signal(SIGTERM, $previousTerminateHandler);
            \pcntl_async_signals($previousAsyncSignals);
            if ($sourceLockAcquired) {
                try {
                    $store->releaseLock($sourceKey);
                } catch (Throwable) {
                    // Closing the dedicated CLI connection releases the session lock.
                }
            }
            if ($databaseLockAcquired) {
                try {
                    $store->releaseDatabaseLock(true);
                } catch (Throwable) {
                    // Closing the dedicated CLI connection releases the session lock.
                }
            }
            \pg_close($connection);
        }
    }

    private static function reportedFailure(Throwable $failure): ImportGarRawFailed
    {
        if ($failure instanceof ImportGarRawFailed) {
            return $failure;
        }
        if ($failure instanceof RunFailed) {
            $primary = self::primaryFailure($failure->result);
            $diagnostics = \array_map(
                GarRawDiagnosticMapper::map(...),
                $failure->result->diagnostics(),
            );
            $detail = $primary === null
                ? $failure->getMessage()
                : "{$primary->type}: {$primary->message}";

            return new ImportGarRawFailed(
                'GAR raw work-orchestrator run failed: ' . $detail,
                diagnostics: $diagnostics,
                previous: $failure,
            );
        }

        return new ImportGarRawFailed(
            'GAR raw work-orchestrator import failed: ' . $failure->getMessage(),
            previous: $failure,
        );
    }

    /** @param RunResult<mixed> $result */
    private static function primaryFailure(RunResult $result): ?FailureDiagnostic
    {
        $fallback = null;
        foreach ($result->results() as $work) {
            $diagnostic = $work->outcome->failure;
            if ($diagnostic === null) {
                continue;
            }
            $fallback ??= $diagnostic;
            if (!self::isSecondaryFailure($diagnostic)) {
                return $diagnostic;
            }
        }

        foreach ($result->diagnostics() as $diagnostic) {
            $fallback ??= $diagnostic;
            if (!self::isSecondaryFailure($diagnostic)) {
                return $diagnostic;
            }
        }

        return $fallback;
    }

    private static function isSecondaryFailure(FailureDiagnostic $diagnostic): bool
    {
        return $diagnostic->kind === FailureKind::Cancellation
            || $diagnostic->kind === FailureKind::Cleanup;
    }
}
