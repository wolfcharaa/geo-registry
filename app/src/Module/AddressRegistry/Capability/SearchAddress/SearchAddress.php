<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\SearchAddress;

use App\Module\AddressRegistry\Capability\AddressHierarchy;
use Fedor\MessageBus\Message\Query;

/** @implements Query<SearchAddressResult> */
final readonly class SearchAddress implements Query
{
    public string $query;
    public int $limit;

    public function __construct(
        string $query,
        public AddressHierarchy $hierarchy = AddressHierarchy::Administrative,
        public SearchProfile $profile = SearchProfile::Search,
        int $limit = 10,
        public ?int $regionCode = null,
    ) {
        $normalized = preg_replace('/\s+/u', ' ', trim($query));
        $this->query = $normalized ?? trim($query);
        $this->limit = max(1, min(50, $limit));
    }
}
