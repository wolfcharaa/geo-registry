<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\OpenSearch\Capability\CleanupProjection;

final readonly class CleanupOpenSearchIndexRunner
{
    public function __construct(
        private CleanupOpenSearchIndexPort $cleaner,
    ) {}

    public function run(CleanupOpenSearchIndex $command): CleanupOpenSearchIndexResult
    {
        return $this->cleaner->cleanup($command);
    }
}
