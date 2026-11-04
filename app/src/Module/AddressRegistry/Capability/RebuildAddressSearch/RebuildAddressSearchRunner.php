<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\RebuildAddressSearch;

final readonly class RebuildAddressSearchRunner
{
    public function __construct(
        private RebuildAddressSearchPort $rebuilder,
    ) {}

    public function run(RebuildAddressSearch $command): RebuildAddressSearchResult
    {
        return $this->rebuilder->rebuild($command);
    }
}
