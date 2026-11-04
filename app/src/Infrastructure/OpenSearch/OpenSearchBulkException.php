<?php

declare(strict_types=1);

namespace App\Infrastructure\OpenSearch;

final class OpenSearchBulkException extends OpenSearchException
{
    public function __construct(
        public readonly int $failedItems,
        public readonly ?string $documentId,
        public readonly ?int $itemStatus,
        public readonly ?string $errorType,
        public readonly ?string $errorReason,
    ) {
        parent::__construct(sprintf(
            'OpenSearch bulk request failed for %d item(s); first failure: id=%s status=%s type=%s reason=%s.',
            $failedItems,
            $documentId ?? 'unknown',
            $itemStatus === null ? 'unknown' : (string) $itemStatus,
            $errorType ?? 'unknown',
            $errorReason ?? 'unknown',
        ));
    }
}
