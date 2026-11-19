<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use App\Module\AddressRegistry\Capability\ImportGar\ImportGar;
use App\Module\AddressRegistry\Capability\ImportGar\ImportGarPort;
use App\Module\AddressRegistry\Capability\ImportGar\ImportGarResult;
use Throwable;

final readonly class GarXmlImportAdapter implements ImportGarPort
{
    public function __construct(
        private GarImportConnectionFactory $connectionFactory,
        private GarXmlCatalog $catalog,
        private GarRawImportCoordinator $rawImport,
        private GarInitialLoadFinalizer $initialLoadFinalizer,
        private GarImportPipelineSelector $pipelineSelector,
    ) {}

    public function import(ImportGar $command): ImportGarResult
    {
        $manifest = $this->catalog->manifest($command->sourcePath, $command->regions);
        $sourcePath = $manifest->sourcePath;
        $files = $manifest->files;
        $sourceKey = \hash('sha256', $sourcePath);
        $pipelineFiles = $this->pipelineSelector->select($command->pipelineFile, $files);
        $pipelineSelectionKey = $pipelineFiles === [] ? null : $this->pipelineSelector->identity($pipelineFiles);
        $connection = $this->connectionFactory->connect();
        $store = new PostgresGarImportStore($connection);
        $initialLoad = new GarInitialLoadStore($connection);
        $databaseLockAcquired = false;
        $lockAcquired = false;
        $operationId = null;

        try {
            $store->acquireDatabaseLock($command->initialLoad);
            $databaseLockAcquired = true;
            $store->ensureNoInitialLoadRecoveryRequired($command->initialLoad);
            $store->acquireLock($sourceKey);
            $lockAcquired = true;
            $operation = $store->startOperation(
                sourceKey: $sourceKey,
                sourcePath: $sourcePath,
                manifestHash: $manifest->hash,
                batchSize: $command->batchSize,
                restart: $command->restart,
                initialLoad: $command->initialLoad,
                directCopy: $command->directCopy,
                pipelineFileKey: $pipelineSelectionKey,
                pipelineWriters: $command->pipelineWriters,
                deferPrimaryKeys: $command->deferPrimaryKeys,
                deferForeignKeys: $command->deferForeignKeys,
                pipelineFileKeys: \array_map(
                    static fn(GarXmlSourceFile $file): string => $file->key,
                    $pipelineFiles,
                ),
                files: $files,
            );
            $operationId = $operation->id;

            if ($operation->alreadyCompleted) {
                return $store->result($operation->id, false, true);
            }

            if ($command->initialLoad) {
                $initialLoad->prepare($operation->id, $this->catalog->tables());
            }

            $pendingFiles = \array_values(\array_filter(
                $files,
                static fn(GarXmlSourceFile $file): bool => !$store
                    ->fileState($operation->id, $file->key)
                    ->completed,
            ));
            $this->rawImport->run(
                $command,
                $operation->id,
                $sourcePath,
                $pipelineFiles,
                $pendingFiles,
            );

            if ($command->initialLoad) {
                $this->initialLoadFinalizer->complete(
                    $operation->id,
                    $initialLoad,
                    $command->indexWorkers,
                    $command->foreignKeyWorkers,
                    $command->persistentIndexWorkers,
                    $command->overlapIndexForeignKeys,
                );
            } else {
                $store->completeOperation($operation->id);
            }

            return $store->result($operation->id, $operation->resumed, false);
        } catch (Throwable $error) {
            if ($operationId !== null) {
                $store->failOperation($operationId, null, $error);
            }
            if ($error instanceof GarImportFailed) {
                throw $error;
            }

            throw new GarImportFailed('GAR import failed: ' . $error->getMessage(), previous: $error);
        } finally {
            if ($lockAcquired) {
                try {
                    $store->releaseLock($sourceKey);
                } catch (Throwable) {
                    // Closing the dedicated CLI connection releases the session lock.
                }
            }
            if ($databaseLockAcquired) {
                try {
                    $store->releaseDatabaseLock($command->initialLoad);
                } catch (Throwable) {
                    // Closing the dedicated CLI connection releases the session lock.
                }
            }
            \pg_close($connection);
        }
    }

}
