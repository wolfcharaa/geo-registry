<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\FindAddressById;

use RuntimeException;
use Throwable;

final class FindAddressByIdUnavailable extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('Address lookup is unavailable.', previous: $previous);
    }
}
