<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\SearchAddress;

use Fedor\MessageBus\Attribute\QueryHandler;
use Fedor\MessageBus\Context\MessageContextInterface;

#[QueryHandler(
    message: SearchAddress::class,
    bindingId: 'address-registry.search-address.v1',
    ownerKind: 'module',
    ownerId: 'AddressRegistry',
)]
final readonly class SearchAddressHandler
{
    public function __construct(private SearchAddressPort $reader) {}

    public function __invoke(SearchAddress $query, MessageContextInterface $context): SearchAddressResult
    {
        if ($query->query === '') {
            return new SearchAddressResult([]);
        }

        return new SearchAddressResult($this->reader->search(
            new SearchAddressCriteria(
                query: $query->query,
                hierarchy: $query->hierarchy,
                profile: $query->profile,
                limit: $query->limit,
                regionCode: $query->regionCode,
            ),
            $context->cancellation(),
        ));
    }
}
