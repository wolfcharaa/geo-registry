<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\FindAddressById;

use Fedor\MessageBus\Attribute\QueryHandler;
use Fedor\MessageBus\Context\MessageContextInterface;

#[QueryHandler(
    message: FindAddressById::class,
    bindingId: 'address-registry.find-address-by-id.v1',
    ownerKind: 'module',
    ownerId: 'AddressRegistry',
)]
final readonly class FindAddressByIdHandler
{
    public function __construct(private FindAddressByIdPort $reader) {}

    public function __invoke(FindAddressById $query, MessageContextInterface $context): FindAddressByIdResult
    {
        if ($query->id === '') {
            return new FindAddressByIdResult([]);
        }

        return new FindAddressByIdResult($this->reader->find(
            $query->id,
            $query->limit,
            $context->cancellation(),
        ));
    }
}
