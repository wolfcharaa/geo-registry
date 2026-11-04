<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

final readonly class GarImportOperationState
{
    public function __construct(
        public int $id,
        public bool $resumed,
        public bool $alreadyCompleted,
    ) {}
}
