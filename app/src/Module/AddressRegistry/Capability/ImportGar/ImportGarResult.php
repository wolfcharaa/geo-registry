<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\ImportGar;

final readonly class ImportGarResult
{
    public function __construct(
        public int $operationId,
        public int $filesTotal,
        public int $filesCompleted,
        public int $rowsProcessed,
        public int $rowsMerged,
        public bool $resumed,
        public bool $alreadyCompleted,
        public bool $initialLoad = false,
        public string $phase = 'ready',
        public int $indexesTotal = 0,
        public int $indexesCompleted = 0,
        public bool $directCopy = false,
        public ?string $pipelineFile = null,
        public ?int $pipelineWriters = null,
        public int $pipelineBatches = 0,
        public int $pipelineBytes = 0,
        public int $pipelinePeakInFlightBatches = 0,
        public int $pipelinePeakInFlightBytes = 0,
        public bool $deferPrimaryKeys = false,
        public bool $deferForeignKeys = false,
        public int $foreignKeysTotal = 0,
        public int $foreignKeysCompleted = 0,
    ) {}
}
