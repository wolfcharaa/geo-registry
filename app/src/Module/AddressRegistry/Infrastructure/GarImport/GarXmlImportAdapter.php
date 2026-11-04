<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use App\Module\AddressRegistry\Capability\ImportGar\ImportGar;
use App\Module\AddressRegistry\Capability\ImportGar\ImportGarPort;
use App\Module\AddressRegistry\Capability\ImportGar\ImportGarResult;
use PgSql\Connection;
use Throwable;

final readonly class GarXmlImportAdapter implements ImportGarPort
{
    public function __construct(
        private string $databaseDsn,
        private GarXmlCatalog $catalog,
        private GarXmlRowReader $reader,
        private GarCopyRowEncoder $encoder,
    ) {}

    public function import(ImportGar $command): ImportGarResult
    {
        $sourcePath = $this->catalog->canonicalSourcePath($command->sourcePath);
        $files = $this->catalog->files($sourcePath);
        $sourceKey = hash('sha256', $sourcePath);
        $manifestHash = $this->catalog->manifestHash($files);
        $connection = $this->connect();
        $store = new PostgresGarImportStore($connection);
        $lockAcquired = false;
        $operationId = null;
        $currentFileKey = null;

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

            foreach ($files as $file) {
                $currentFileKey = $file->key;
                $state = $store->fileState($operation->id, $file->key);
                if ($state->completed) {
                    continue;
                }

                $store->startFile($operation->id, $file->key);
                $processedRows = $state->processedRows;
                $batch = [];

                foreach ($this->reader->rows($file, $state->processedRows) as $rowNumber => $row) {
                    $batch[] = $this->encoder->encode($row);
                    $processedRows = $rowNumber;

                    if (count($batch) >= $command->batchSize) {
                        $store->mergeBatch($operation->id, $file, $batch, $processedRows);
                        $batch = [];
                    }
                }

                $store->mergeBatch($operation->id, $file, $batch, $processedRows);
                $store->completeFile($operation->id, $file->key);
            }

            $store->completeOperation($operation->id);

            return $store->result($operation->id, $operation->resumed, false);
        } catch (Throwable $error) {
            if ($operationId !== null) {
                $store->failOperation($operationId, $currentFileKey, $error);
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

    private function connect(): Connection
    {
        $connectionString = preg_replace('/(^|\s)db=/', '$1dbname=', $this->databaseDsn);
        if (!is_string($connectionString)) {
            throw new GarImportFailed('Unable to normalize PostgreSQL connection settings for GAR import.');
        }

        $connection = pg_connect($connectionString, PGSQL_CONNECT_FORCE_NEW);
        if (!$connection instanceof Connection) {
            throw new GarImportFailed('Unable to connect to PostgreSQL for GAR import.');
        }

        return $connection;
    }
}
