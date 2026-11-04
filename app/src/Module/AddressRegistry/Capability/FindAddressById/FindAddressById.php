<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\FindAddressById;

use Fedor\MessageBus\Message\Query;

/** @implements Query<FindAddressByIdResult> */
final readonly class FindAddressById implements Query
{
    public string $id;
    public int $limit;

    public function __construct(string $id, int $limit = 20)
    {
        $this->id = trim($id);
        $this->limit = max(1, min(20, $limit));
    }
}
