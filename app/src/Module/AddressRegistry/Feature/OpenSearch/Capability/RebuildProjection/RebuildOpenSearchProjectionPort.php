<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\OpenSearch\Capability\RebuildProjection;

interface RebuildOpenSearchProjectionPort
{
    public function rebuild(RebuildOpenSearchProjection $command): RebuildOpenSearchProjectionResult;
}
