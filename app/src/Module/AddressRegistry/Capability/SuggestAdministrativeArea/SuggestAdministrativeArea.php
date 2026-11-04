<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\SuggestAdministrativeArea;

use App\Module\AddressRegistry\Capability\AddressHierarchy;
use App\Module\AddressRegistry\Capability\SuggestionContext;
use Fedor\MessageBus\Message\Query;

/** @implements Query<SuggestAdministrativeAreaResult> */
final readonly class SuggestAdministrativeArea implements Query
{
    public string $query;
    public int $limit;

    public function __construct(
        string $query,
        public AddressHierarchy $hierarchy,
        int $limit,
        public ?SuggestionContext $context,
    ) {
        $normalized = preg_replace('/\s+/u', ' ', trim($query));
        $this->query = $normalized ?? trim($query);
        $this->limit = max(1, min(7, $limit));
    }
}
