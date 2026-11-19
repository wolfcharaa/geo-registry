<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Capability\ImportGar\ImportGar;

/** Runs the raw file phase with optional early secondary-index overlap. */
final readonly class GarRawImportCoordinator
{
    public function __construct(
        private GarImportWorkerPool $workerPool,
        private GarInitialLoadIndexPool $indexPool,
    ) {}

    /**
     * @param list<GarXmlSourceFile> $pipelineFiles
     * @param list<GarXmlSourceFile> $pendingFiles
     */
    public function run(
        ImportGar $command,
        int $operationId,
        string $sourcePath,
        array $pipelineFiles,
        array $pendingFiles,
    ): void {
        if (!$command->overlapTableIndexes) {
            $this->runWorkerPool($command, $operationId, $sourcePath, $pipelineFiles, $pendingFiles);
            return;
        }

        $this->indexPool->runAlongsideImport(
            operationId: $operationId,
            workerCount: $command->overlapIndexWorkers,
            startPercent: $command->overlapIndexStartPercent,
            import: fn() => $this->runWorkerPool(
                $command,
                $operationId,
                $sourcePath,
                $pipelineFiles,
                $pendingFiles,
            ),
            persistentWorkers: $command->persistentIndexWorkers,
        );
    }

    /**
     * @param list<GarXmlSourceFile> $pipelineFiles
     * @param list<GarXmlSourceFile> $pendingFiles
     */
    private function runWorkerPool(
        ImportGar $command,
        int $operationId,
        string $sourcePath,
        array $pipelineFiles,
        array $pendingFiles,
    ): void {
        $this->workerPool->run(
            operationId: $operationId,
            sourcePath: $sourcePath,
            batchSize: $command->batchSize,
            workerCount: $command->workers,
            directCopy: $command->directCopy,
            pipelineFiles: $pipelineFiles,
            pipelineWriters: $command->pipelineWriters,
            pipelineParsers: $command->pipelineParsers,
            pipelineFusedRanges: $command->pipelineFusedRanges,
            pipelineFileConcurrency: $command->pipelineFileConcurrency,
            pipelineBufferMiB: $command->pipelineBufferMiB,
            pipelineFragmentMiB: $command->pipelineFragmentMiB,
            pipelineTableAwareFiles: $command->pipelineTableAwareFiles,
            files: $pendingFiles,
        );
    }
}
