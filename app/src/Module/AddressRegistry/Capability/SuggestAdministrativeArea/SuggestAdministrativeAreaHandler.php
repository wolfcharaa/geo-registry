<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\SuggestAdministrativeArea;

use App\Module\AddressRegistry\Capability\SuggestionCriteria;
use Fedor\MessageBus\Attribute\QueryHandler;
use Fedor\MessageBus\Context\MessageContextInterface;

#[QueryHandler(
    message: SuggestAdministrativeArea::class,
    bindingId: 'address-registry.suggest-administrative-area.v1',
    ownerKind: 'module',
    ownerId: 'AddressRegistry',
)]
final readonly class SuggestAdministrativeAreaHandler
{
    public function __construct(private SuggestAdministrativeAreaPort $reader) {}

    public function __invoke(
        SuggestAdministrativeArea $query,
        MessageContextInterface $context,
    ): SuggestAdministrativeAreaResult {
        if ($query->query === '') {
            return new SuggestAdministrativeAreaResult([]);
        }

        return new SuggestAdministrativeAreaResult($this->reader->suggestAdministrativeAreas(
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
