<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use App\Module\AddressRegistry\Capability\ImportGar\RunGarImportWorker;
use App\Module\AddressRegistry\Capability\ImportGar\RunGarImportWorkerPort;

final readonly class GarXmlImportWorkerAdapter implements RunGarImportWorkerPort
{
    public function __construct(
        private GarImportConnectionFactory $connectionFactory,
        private GarXmlCatalog $catalog,
        private GarXmlFileImporter $fileImporter,
    ) {}

    public function run(RunGarImportWorker $command): void
    {
        $connection = $this->connectionFactory->connect();
        $store = new PostgresGarImportStore($connection);
        $directCopyMapper = new GarDirectCopyRowMapper($connection);

        try {
            $manifest = $this->catalog->manifest($command->sourcePath);
            $store->assertWorkerWriteMode($command->operationId, $command->directCopy);
            foreach ($command->files as $assignedFile) {
                $file = $manifest->file($assignedFile->relativePath);
                if (!\hash_equals($file->key, $assignedFile->fileKey)) {
                    throw new GarImportFailed(
                        "GAR XML file changed after the import manifest was created: {$assignedFile->relativePath}",
                    );
                }

                $this->fileImporter->import(
                    store: $store,
                    directCopyMapper: $directCopyMapper,
                    operationId: $command->operationId,
                    batchSize: $command->batchSize,
                    directCopy: $command->directCopy,
                    file: $file,
                );
            }
        } finally {
            \pg_close($connection);
        }
    }
}
