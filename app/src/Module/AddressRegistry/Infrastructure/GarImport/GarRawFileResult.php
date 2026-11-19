<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

/** Compact primary result retained by the root raw-import ledger. */
final readonly class GarRawFileResult
{
    public function __construct(
        public string $fileKey,
        public int $rangeTasks,
        public int $managedTaskRequestBytes,
        public int $managedTaskResultBytes,
    ) {}
}
