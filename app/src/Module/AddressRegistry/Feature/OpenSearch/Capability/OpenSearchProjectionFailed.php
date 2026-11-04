<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\OpenSearch\Capability;

use RuntimeException;
use Throwable;

final class OpenSearchProjectionFailed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $targetIndex = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
