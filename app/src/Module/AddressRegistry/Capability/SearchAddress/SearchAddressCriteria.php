<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\SearchAddress;

use App\Module\AddressRegistry\Capability\AddressHierarchy;

final readonly class SearchAddressCriteria
{
    public function __construct(
        public string $query,
        public AddressHierarchy $hierarchy,
        public SearchProfile $profile,
        public int $limit,
        public ?int $regionCode,
    ) {}
}
