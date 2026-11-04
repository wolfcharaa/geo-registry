<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\UserInterface\Http\SuggestAddress;

use RuntimeException;
use Throwable;

final class InvalidSuggestAddressRequest extends RuntimeException
{
    /** @param array<string, int|string|null> $query */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $query = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
