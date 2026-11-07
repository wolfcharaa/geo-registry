<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\OpenSearch\Capability\ProjectionStatus;

interface OpenSearchProjectionStatusInspector
{
    public function inspect(): OpenSearchProjectionStatusReport;
}
