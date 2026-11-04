<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\OpenSearch\Infrastructure\Rebuild;

final readonly class OpenSearchProjectionManifest
{
    public function __construct(
        public int $documents,
        public int $invalidRegionDocuments,
    ) {}
}
