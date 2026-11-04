<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\AddressSearchRebuild;

final readonly class AddressSearchRebuildTask
{
    public function __construct(
        public int $regionCode,
        public string $hierarchy,
        public int $lastHierarchyId,
        public int $sourceMaxHierarchyId,
        public bool $completed,
    ) {}
}
