<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\FindAddressById;

use App\Module\AddressRegistry\Capability\AddressDocument;

final readonly class FindAddressByIdResult
{
    /** @param list<AddressDocument> $documents */
    public function __construct(public array $documents) {}
}
