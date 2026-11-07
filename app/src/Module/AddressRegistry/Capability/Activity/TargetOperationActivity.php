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
        public ?string $error,
    ) {}
}
