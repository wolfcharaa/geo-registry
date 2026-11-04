<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\OpenSearch\Capability\CleanupProjection;

final readonly class CleanupOpenSearchIndexResult
{
    public function __construct(
        public string $deletedIndex,
    ) {}
}
