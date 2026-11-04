<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability;

final readonly class SuggestionCriteria
{
    public function __construct(
        public string $query,
        public AddressHierarchy $hierarchy,
        public int $limit,
        public ?SuggestionContext $context,
    ) {}
}
