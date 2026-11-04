<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\SearchAddress;

use Amp\Cancellation;

interface SearchAddressPort
{
    /** @return list<SearchAddressMatch> */
    public function search(SearchAddressCriteria $criteria, Cancellation $cancellation): array;
}
