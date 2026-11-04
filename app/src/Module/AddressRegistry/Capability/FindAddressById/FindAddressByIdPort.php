<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\FindAddressById;

use Amp\Cancellation;
use App\Module\AddressRegistry\Capability\AddressDocument;

interface FindAddressByIdPort
{
    /** @return list<AddressDocument> */
    public function find(string $id, int $limit, Cancellation $cancellation): array;
}
