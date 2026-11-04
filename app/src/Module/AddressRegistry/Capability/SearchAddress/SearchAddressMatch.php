<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\SearchAddress;

use App\Module\AddressRegistry\Capability\AddressAlias;
use App\Module\AddressRegistry\Capability\AddressDocument;

final readonly class SearchAddressMatch
{
    /** @param list<AddressAlias> $aliases */
    public function __construct(
        public AddressDocument $document,
        public float $score,
        public array $aliases,
        public ?string $matchedAlias,
    ) {}
}
