<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\UserInterface\Http\FindAddressById;

use RuntimeException;
use Throwable;

final class InvalidFindAddressByIdRequest extends RuntimeException
{
    public function __construct(
        public readonly string $field,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public function errorCode(): string
    {
        return 'invalid_' . $this->field;
    }
}
