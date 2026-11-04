<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\RebuildAddressSearch;

interface RebuildAddressSearchPort
{
    public function rebuild(RebuildAddressSearch $command): RebuildAddressSearchResult;
}
