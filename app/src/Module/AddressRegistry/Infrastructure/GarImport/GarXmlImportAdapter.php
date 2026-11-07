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
        private GarImportWorkerPool $workerPool,
    ) {}

    public function import(ImportGar $command): ImportGarResult
    {
        $sourcePath = $this->catalog->canonicalSourcePath($command->sourcePath);
        $files = $this->catalog->files($sourcePath, $command->regions);
        $sourceKey = hash('sha256', $sourcePath);
        $manifestHash = $this->catalog->manifestHash($files);
        $connection = $this->connectionFactory->connect();
        $store = new PostgresGarImportStore($connection);
        $lockAcquired = false;
        $operationId = null;

        try {
            $store->acquireLock($sourceKey);
            $lockAcquired = true;
            $operation = $store->startOperation(
                sourceKey: $sourceKey,
                sourcePath: $sourcePath,
                manifestHash: $manifestHash,
                batchSize: $command->batchSize,
                restart: $command->restart,
                files: $files,
            );
            $operationId = $operation->id;

            if ($operation->alreadyCompleted) {
                return $store->result($operation->id, false, true);
            }

            $pendingFiles = array_values(array_filter(
                $files,
                static fn(GarXmlSourceFile $file): bool => !$store
                    ->fileState($operation->id, $file->key)
                    ->completed,
            ));
            $this->workerPool->run(
                operationId: $operation->id,
                sourcePath: $sourcePath,
                batchSize: $command->batchSize,
                workerCount: $command->workers,
                files: $pendingFiles,
            );

            $store->completeOperation($operation->id);

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
            pg_close($connection);
        }
    }
}
