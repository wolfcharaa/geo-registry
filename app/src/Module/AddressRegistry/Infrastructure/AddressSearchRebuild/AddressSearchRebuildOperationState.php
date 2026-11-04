<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\AddressSearchRebuild;

final readonly class AddressSearchRebuildOperationState
{
    /** @param list<string> $abandonedTables */
    public function __construct(
        public int $id,
        public string $shadowTable,
        public bool $resumed,
        public bool $ready,
        public array $abandonedTables = [],
    ) {}
}
