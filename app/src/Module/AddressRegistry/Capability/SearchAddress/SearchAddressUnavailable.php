<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\SearchAddress;

use RuntimeException;
use Throwable;

final class SearchAddressUnavailable extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('Address search is unavailable.', previous: $previous);
    }
}
