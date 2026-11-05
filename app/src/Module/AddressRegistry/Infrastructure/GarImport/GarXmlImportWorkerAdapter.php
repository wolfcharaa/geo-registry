<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use App\Module\AddressRegistry\Capability\ImportGar\GarImportWorkerFile;
use App\Module\AddressRegistry\Capability\ImportGar\RunGarImportWorker;
use App\Module\AddressRegistry\Capability\ImportGar\RunGarImportWorkerPort;
use Throwable;

final readonly class GarXmlImportWorkerAdapter implements RunGarImportWorkerPort
{
    public function __construct(
        private GarImportConnectionFactory $connectionFactory,
        private GarXmlCatalog $catalog,
        private GarXmlRowReader $reader,
        private GarCopyRowEncoder $encoder,
    ) {}

    public function run(RunGarImportWorker $command): void
    {
        $connection = $this->connectionFactory->connect();
        $store = new PostgresGarImportStore($connection);

        try {
            foreach ($command->files as $assignedFile) {
                $this->importFile($store, $command, $assignedFile);
            }
        } finally {
            pg_close($connection);
        }
    }

    private function importFile(
        PostgresGarImportStore $store,
        RunGarImportWorker $command,
        GarImportWorkerFile $assignedFile,
    ): void {
        $file = null;

        try {
            $file = $this->catalog->file($command->sourcePath, $assignedFile->relativePath);
            if (!hash_equals($file->key, $assignedFile->fileKey)) {
                throw new GarImportFailed(
                    "GAR XML file changed after the import manifest was created: {$assignedFile->relativePath}",
                );
            }

            $state = $store->fileState($command->operationId, $file->key);
            if ($state->completed) {
                return;
            }

            $store->startFile($command->operationId, $file->key);
            $store->prepareStage($file);
            $processedRows = $state->processedRows;
            $batch = [];

            foreach ($this->reader->rows($file, $state->processedRows) as $rowNumber => $row) {
                $batch[] = $this->encoder->encode($row);
                $processedRows = $rowNumber;

                if (count($batch) >= $command->batchSize) {
                    $store->mergeBatch($command->operationId, $file, $batch, $processedRows);
                    $batch = [];
                }
            }

            $store->mergeBatch($command->operationId, $file, $batch, $processedRows);
            $store->completeFile($command->operationId, $file->key);
        } catch (Throwable $error) {
            $store->failFile($command->operationId, $file->key ?? $assignedFile->fileKey, $error);
            if ($error instanceof GarImportFailed) {
                throw $error;
            }

            throw new GarImportFailed(sprintf(
                'GAR import worker failed for %s: %s',
                $assignedFile->relativePath,
                $error->getMessage(),
            ), previous: $error);
        }
    }
}
