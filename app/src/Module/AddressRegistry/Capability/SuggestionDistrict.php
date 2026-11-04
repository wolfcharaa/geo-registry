<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability;

final readonly class SuggestionDistrict
{
    public function __construct(
        public int $objectId,
        public string $name,
    ) {}
}
