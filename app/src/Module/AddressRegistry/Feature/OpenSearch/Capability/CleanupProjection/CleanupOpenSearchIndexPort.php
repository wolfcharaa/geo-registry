<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\OpenSearch\Capability\CleanupProjection;

interface CleanupOpenSearchIndexPort
{
    public function cleanup(CleanupOpenSearchIndex $command): CleanupOpenSearchIndexResult;
}
