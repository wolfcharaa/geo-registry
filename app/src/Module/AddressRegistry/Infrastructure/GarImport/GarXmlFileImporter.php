<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use Amp\Cancellation;
use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use Throwable;

/** Imports one manifest-verified GAR XML file using an existing worker connection. */
final readonly class GarXmlFileImporter
{
    public function __construct(
        private GarXmlRowReader $reader,
        private GarCopyRowEncoder $encoder,
    ) {}

    public function import(
        PostgresGarImportStore $store,
        GarDirectCopyRowMapper $directCopyMapper,
        int $operationId,
        int $batchSize,
        bool $directCopy,
        GarXmlSourceFile $file,
        ?Cancellation $cancellation = null,
    ): string {
        try {
            $cancellation?->throwIfRequested();
            $state = $store->fileState($operationId, $file->key);
            if ($state->completed) {
                return $file->key;
            }

            $store->startFile($operationId, $file->key);
            if (!$directCopy) {
                $store->prepareStage($file);
            }
            $processedRows = $state->processedRows;
            $batch = [];

            foreach ($this->reader->rows($file, $state->processedRows, $cancellation) as $rowNumber => $row) {
                $values = $directCopy
                    ? $directCopyMapper->map($file->descriptor, $row)
                    : $row;
                $batch[] = $this->encoder->encode($values);
                $processedRows = $rowNumber;

                if (\count($batch) < $batchSize) {
                    continue;
                }

                $cancellation?->throwIfRequested();
                $store->mergeBatch(
                    $operationId,
                    $file,
                    $batch,
                    $processedRows,
                    $directCopy,
                );
                $batch = [];
            }

            $cancellation?->throwIfRequested();
            $store->mergeBatch(
                $operationId,
                $file,
                $batch,
                $processedRows,
                $directCopy,
            );
            $store->completeFile($operationId, $file->key);

            return $file->key;
        } catch (Throwable $error) {
            $store->failFile($operationId, $file->key, $error);
            if ($error instanceof GarImportFailed) {
                throw $error;
            }

            throw new GarImportFailed(\sprintf(
                'GAR import worker failed for %s: %s',
                $file->relativePath,
                $error->getMessage(),
            ), previous: $error);
        }
    }
}
