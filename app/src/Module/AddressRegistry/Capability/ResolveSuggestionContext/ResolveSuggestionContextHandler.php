<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\ResolveSuggestionContext;

use App\Module\AddressRegistry\Capability\SuggestionContext;
use Fedor\MessageBus\Attribute\QueryHandler;
use Fedor\MessageBus\Context\MessageContextInterface;

#[QueryHandler(
    message: ResolveSuggestionContext::class,
    bindingId: 'address-registry.resolve-suggestion-context.v1',
    ownerKind: 'module',
    ownerId: 'AddressRegistry',
)]
final readonly class ResolveSuggestionContextHandler
{
    public function __construct(private ResolveSuggestionContextPort $reader) {}

    public function __invoke(
        ResolveSuggestionContext $query,
        MessageContextInterface $context,
    ): ?SuggestionContext {
        return $this->reader->resolve(
            $query->parentId,
            $query->hierarchy,
            $context->cancellation(),
        );
    }
}
