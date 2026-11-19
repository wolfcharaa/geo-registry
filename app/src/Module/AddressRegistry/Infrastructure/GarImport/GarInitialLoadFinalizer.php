<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;

/** Advances a resumable initial load from raw import through ready state. */
final readonly class GarInitialLoadFinalizer
{
    public function __construct(
        private GarXmlCatalog $catalog,
        private GarInitialLoadIndexPool $indexPool,
        private GarForeignKeyValidationPool $foreignKeyPool,
        private GarInitialLoadOverlapPool $overlapPool,
    ) {}

    public function complete(
        int $operationId,
        GarInitialLoadStore $store,
        int $indexWorkers,
        int $foreignKeyWorkers,
        bool $persistentIndexWorkers,
        bool $overlapIndexForeignKeys,
    ): void {
        while (true) {
            $phase = $store->phase($operationId);
            if ($phase === 'import') {
                $store->beginIndexBuild($operationId);
                continue;
            }
            if ($phase === 'index-build') {
                $store->resumeIndexBuild($operationId);
                $primaryKeys = $store->pendingPrimaryIndexes($operationId);
                if ($primaryKeys !== []) {
                    $this->indexPool->run(
                        $operationId,
                        $primaryKeys,
                        $indexWorkers,
                        $persistentIndexWorkers,
                    );
                }
                if ($overlapIndexForeignKeys) {
                    $store->restoreForeignKeysForIndexOverlap($operationId);
                    $this->overlapPool->run(
                        operationId: $operationId,
                        indexes: $store->pendingIndexes($operationId),
                        foreignKeys: $store->pendingForeignKeys($operationId),
                        indexWorkers: $indexWorkers,
                        foreignKeyWorkers: $foreignKeyWorkers,
                        persistentIndexWorkers: $persistentIndexWorkers,
                    );
                } else {
                    $this->indexPool->run(
                        $operationId,
                        $store->pendingIndexes($operationId),
                        $indexWorkers,
                        $persistentIndexWorkers,
                    );
                }
                $store->completeIndexBuild($operationId);
                continue;
            }
            if ($phase === 'foreign-key-restore') {
                $store->restoreForeignKeys($operationId);
                continue;
            }
            if ($phase === 'foreign-key-validate') {
                $store->resumeForeignKeyValidation($operationId);
                $this->foreignKeyPool->run(
                    $operationId,
                    $store->pendingForeignKeys($operationId),
                    $foreignKeyWorkers,
                );
                $store->completeForeignKeyValidation($operationId);
                continue;
            }
            if ($phase === 'analyze') {
                $store->analyze($operationId, $this->catalog->tables());
                continue;
            }
            if ($phase === 'integrity') {
                $store->complete($operationId, $this->catalog->tables());
                continue;
            }
            if ($phase === 'ready') {
                return;
            }

            throw new GarImportFailed("GAR initial-load cannot finalize from phase {$phase}.");
        }
    }
}
