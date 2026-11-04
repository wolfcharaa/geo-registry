<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability;

final readonly class AddressAlias
{
    public function __construct(
        public string $value,
        public string $kind,
        public string $source,
        public string $sourceObjectType,
        public int $sourceObjectId,
        public int $rank,
    ) {}
}
