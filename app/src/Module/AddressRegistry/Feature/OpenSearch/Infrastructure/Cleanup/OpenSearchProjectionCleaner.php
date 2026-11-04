<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\OpenSearch\Infrastructure\Cleanup;

use App\Infrastructure\OpenSearch\OpenSearchClient;
use App\Module\AddressRegistry\Feature\OpenSearch\Capability\CleanupProjection\CleanupOpenSearchIndex;
use App\Module\AddressRegistry\Feature\OpenSearch\Capability\CleanupProjection\CleanupOpenSearchIndexPort;
use App\Module\AddressRegistry\Feature\OpenSearch\Capability\CleanupProjection\CleanupOpenSearchIndexResult;
use App\Module\AddressRegistry\Feature\OpenSearch\Capability\OpenSearchProjectionFailed;

final readonly class OpenSearchProjectionCleaner implements CleanupOpenSearchIndexPort
{
    public function __construct(
        private OpenSearchClient $openSearch,
        private string $alias,
    ) {}

    public function cleanup(CleanupOpenSearchIndex $command): CleanupOpenSearchIndexResult
    {
        if (!str_starts_with($command->index, $this->alias . '-v')) {
            throw new OpenSearchProjectionFailed(
                "Cleanup is restricted to versioned indexes of alias `{$this->alias}`.",
            );
        }
        if (in_array($command->index, $this->openSearch->aliasTargets($this->alias), true)) {
            throw new OpenSearchProjectionFailed(
                "OpenSearch index `{$command->index}` is active for alias `{$this->alias}` and cannot be deleted.",
            );
        }
        if (!$this->openSearch->concreteIndexExists($command->index)) {
            throw new OpenSearchProjectionFailed("OpenSearch index `{$command->index}` does not exist.");
        }

        $this->openSearch->deleteIndex($command->index);

        return new CleanupOpenSearchIndexResult($command->index);
    }
}
