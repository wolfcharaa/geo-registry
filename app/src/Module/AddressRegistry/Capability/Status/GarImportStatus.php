<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\Status;

final readonly class GarImportStatus
{
    public function __construct(
        public int $operationId,
        public string $status,
        public string $mode,
        public string $phase,
        public string $writeMode,
        public int $attempt,
        public int $batchSize,
        public string $sourcePath,
        public string $manifestHash,
        public int $filesCompleted,
        public int $filesTotal,
        public int $rowsProcessed,
        public int $rowsMerged,
        public string $updatedAt,
        public int $pendingFiles,
        public int $runningFiles,
        public int $completedFiles,
        public int $failedFiles,
        public string $progressAge,
        public string $pipelineState,
        public ?string $pipelineFile,
        public ?int $pipelineWriters,
        public int $pipelineBatches,
        public int $pipelineBytes,
        public int $pipelinePeakInFlightBatches,
        public int $pipelinePeakInFlightBytes,
        public int $pipelinePendingRanges,
        public int $pipelinePendingRows,
        public ?string $pipelineProgressAge,
        public bool $deferForeignKeys = false,
        public int $foreignKeysCompleted = 0,
        public int $foreignKeysTotal = 0,
    ) {}
}
