<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\SuggestThoroughfare;

use App\Module\AddressRegistry\Capability\SuggestionCriteria;
use Fedor\MessageBus\Attribute\QueryHandler;
use Fedor\MessageBus\Context\MessageContextInterface;

#[QueryHandler(
    message: SuggestThoroughfare::class,
    bindingId: 'address-registry.suggest-thoroughfare.v1',
    ownerKind: 'module',
    ownerId: 'AddressRegistry',
)]
final readonly class SuggestThoroughfareHandler
{
    public function __construct(private SuggestThoroughfarePort $reader) {}

    public function __invoke(
        SuggestThoroughfare $query,
        MessageContextInterface $context,
    ): SuggestThoroughfareResult {
        if ($query->query === '') {
            return new SuggestThoroughfareResult([]);
        }

        return new SuggestThoroughfareResult($this->reader->suggestThoroughfares(
            new SuggestionCriteria(
                query: $query->query,
                hierarchy: $query->hierarchy,
                limit: $query->limit,
                context: $query->context,
            ),
            $context->cancellation(),
        ));
    }
}
