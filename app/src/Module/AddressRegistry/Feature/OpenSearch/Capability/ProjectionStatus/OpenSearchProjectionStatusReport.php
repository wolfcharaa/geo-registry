<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\OpenSearch\Capability\ProjectionStatus;

final readonly class OpenSearchProjectionStatusReport
{
    /**
     * @param list<string> $aliasTargets
     * @param list<string> $inactiveIndexes
     */
    public function __construct(
        public string $alias,
        public string $clusterStatus,
        public bool $clusterTimedOut,
        public int $nodes,
        public int $dataNodes,
        public array $aliasTargets,
        public int $sourceDocuments,
        public int $invalidRegionDocuments,
        public ?int $targetDocuments,
        public array $inactiveIndexes,
    ) {}

    public function isHealthy(): bool
    {
        return in_array($this->clusterStatus, ['green', 'yellow'], true)
            && !$this->clusterTimedOut
            && count($this->aliasTargets) === 1
            && $this->invalidRegionDocuments === 0
            && $this->targetDocuments === $this->sourceDocuments;
    }
}
