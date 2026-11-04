<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\AddressSearchRebuild;

final readonly class AddressSearchBatchBoundary
{
    public function __construct(
        public int $maxHierarchyId,
        public int $selectedRows,
    ) {}
}
