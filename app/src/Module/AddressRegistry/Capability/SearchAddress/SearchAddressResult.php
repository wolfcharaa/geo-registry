<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\SearchAddress;

final readonly class SearchAddressResult
{
    /** @param list<SearchAddressMatch> $matches */
    public function __construct(public array $matches) {}
}
