<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\Status;

final readonly class AddressSearchRebuildStatus
{
    public function __construct(
        public int $operationId,
        public string $status,
        public string $phase,
        public int $tasksCompleted,
        public int $tasksTotal,
        public int $rowsScanned,
        public int $rowsWritten,
        public int $projectionRows,
        public string $updatedAt,
    ) {}
}
