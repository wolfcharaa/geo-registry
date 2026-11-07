<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\Status;

final readonly class GarImportStatus
{
    public function __construct(
        public int $operationId,
        public string $status,
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
    ) {}
}
