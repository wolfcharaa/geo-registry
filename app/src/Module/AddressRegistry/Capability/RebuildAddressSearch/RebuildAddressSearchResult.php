<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\RebuildAddressSearch;

final readonly class RebuildAddressSearchResult
{
    public function __construct(
        public int $operationId,
        public string $status,
        public int $tasksCompleted,
        public int $tasksTotal,
        public int $rowsScanned,
        public int $rowsWritten,
        public int $shadowRows,
        public int $elapsedMilliseconds,
        public bool $resumed,
    ) {}
}
