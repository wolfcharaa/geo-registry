<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability;

final readonly class SuggestionContext
{
    public function __construct(
        public int $parentId,
        public int $regionCode,
        public AddressHierarchy $hierarchy,
        public int $level,
        public ?SuggestionDistrict $district,
    ) {}
}
