<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\OpenSearch\Capability\RebuildProjection;

final readonly class RebuildOpenSearchProjectionResult
{
    /** @param list<string> $previousIndexes */
    public function __construct(
        public string $targetIndex,
        public int $sourceDocuments,
        public int $indexedDocuments,
        public int $targetDocuments,
        public bool $aliasSwitched,
        public array $previousIndexes,
        public int $elapsedMilliseconds,
    ) {}
}
