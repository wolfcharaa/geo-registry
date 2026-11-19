<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\ImportGarRaw;

/** Successful terminal result at the raw boundary; it is not a full GAR import result. */
final readonly class ImportGarRawResult
{
    /** @param list<ImportGarRawDiagnostic> $diagnostics */
    public function __construct(
        public int $operationId,
        public int $filesTotal,
        public int $filesCompleted,
        public int $rowsProcessed,
        public int $rowsMerged,
        public bool $resumed,
        public bool $alreadyCompleted,
        public string $durablePhase,
        public ?string $pipelineFile,
        public int $pipelineBatches,
        public int $pipelineBytes,
        public int $rangeTasks,
        public int $managedTaskRequestBytes,
        public int $managedTaskResultBytes,
        public int $indexesDeferred,
        public int $foreignKeysDeferred,
        public bool $deferPrimaryKeys,
        public bool $deferForeignKeys,
        public float $rawSeconds,
        public array $diagnostics = [],
    ) {}
}
