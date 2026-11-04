<?php

declare(strict_types=1);

namespace App\Infrastructure\OpenSearch;

use RuntimeException;
use Throwable;

class OpenSearchException extends RuntimeException
{
    /** @param array<array-key, mixed>|string|null $response */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly array|string|null $response = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
