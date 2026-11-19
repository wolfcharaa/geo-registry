<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\Activity;

final readonly class TargetOperationActivity
{
    public function __construct(
        public string $type,
        public int $operationId,
        public string $status,
        public ?string $phase,
        public int $stepsCompleted,
        public int $stepsTotal,
        public int $rowsRead,
        public int $rowsWritten,
        public string $updatedAt,
        public string $progressAge,
        public ?string $error,
        public ?string $mode = null,
        public ?string $writeMode = null,
        public ?int $attempt = null,
        public ?int $batchSize = null,
        public ?string $pipelineState = null,
        public ?string $pipelineFile = null,
        public ?int $pipelineWriters = null,
        public int $pipelineBatches = 0,
        public int $pipelineBytes = 0,
        public int $pipelinePeakInFlightBatches = 0,
        public int $pipelinePeakInFlightBytes = 0,
        public int $pipelinePendingRanges = 0,
        public int $pipelinePendingRows = 0,
        public ?string $pipelineProgressAge = null,
        public bool $deferForeignKeys = false,
        public int $foreignKeysCompleted = 0,
        public int $foreignKeysTotal = 0,
    ) {}
}
