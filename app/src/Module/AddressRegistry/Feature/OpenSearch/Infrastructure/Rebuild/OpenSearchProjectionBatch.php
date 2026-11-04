<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\OpenSearch\Infrastructure\Rebuild;

use App\Module\AddressRegistry\Feature\OpenSearch\Capability\RebuildProjection\OpenSearchProjectionDocument;
use InvalidArgumentException;

final readonly class OpenSearchProjectionBatch
{
    /** @var non-empty-list<OpenSearchProjectionDocument> */
    public array $documents;

    /** @param list<OpenSearchProjectionDocument> $documents */
    public function __construct(
        array $documents,
        public int $lastRegionCode,
        public int $lastRowId,
    ) {
        if ($documents === []) {
            throw new InvalidArgumentException('OpenSearch projection batch must not be empty.');
        }

        $this->documents = $documents;
    }
}
