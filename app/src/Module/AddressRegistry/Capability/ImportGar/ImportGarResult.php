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
    ) {}
}
