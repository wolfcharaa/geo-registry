<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\SuggestAddress;

use App\Module\AddressRegistry\Capability\ResolveSuggestionContext\ResolveSuggestionContext;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddress;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchProfile;
use App\Module\AddressRegistry\Capability\SuggestAdministrativeArea\SuggestAdministrativeArea;
use App\Module\AddressRegistry\Capability\SuggestionContext;
use App\Module\AddressRegistry\Capability\SuggestionContextNotFound;
use App\Module\AddressRegistry\Capability\SuggestionStage;
use App\Module\AddressRegistry\Capability\SuggestThoroughfare\SuggestThoroughfare;
use Fedor\MessageBus\Attribute\QueryHandler;
use Fedor\MessageBus\Context\MessageContextInterface;

#[QueryHandler(
    message: SuggestAddress::class,
    bindingId: 'address-registry.suggest-address.v1',
    ownerKind: 'module',
    ownerId: 'AddressRegistry',
)]
final readonly class SuggestAddressHandler
{
    public function __invoke(SuggestAddress $query, MessageContextInterface $context): SuggestAddressResult
    {
        $suggestionContext = $this->resolveContext($query, $context);

        return match ($query->stage) {
            SuggestionStage::AdministrativeArea => SuggestAddressResult::progressive(
                $context->ask(new SuggestAdministrativeArea(
                    query: $query->suggest,
                    hierarchy: $query->hierarchy,
                    limit: $query->count,
                    context: $suggestionContext,
                ))->suggestions,
            ),
            SuggestionStage::Thoroughfare => SuggestAddressResult::progressive(
                $context->ask(new SuggestThoroughfare(
                    query: $query->suggest,
                    hierarchy: $query->hierarchy,
                    limit: $query->count,
                    context: $suggestionContext ?? throw new SuggestionContextNotFound(),
                ))->suggestions,
            ),
            SuggestionStage::Search => SuggestAddressResult::search(
                $context->ask(new SearchAddress(
                    query: trim($query->exact . ' ' . $query->suggest),
                    hierarchy: $query->hierarchy,
                    profile: SearchProfile::Search,
                    limit: $query->count,
                    regionCode: ($suggestionContext ?? throw new SuggestionContextNotFound())->regionCode,
                ))->matches,
            ),
        };
    }

    private function resolveContext(
        SuggestAddress $query,
        MessageContextInterface $context,
    ): ?SuggestionContext {
        if ($query->parentId === null) {
            if ($query->stage !== SuggestionStage::AdministrativeArea) {
                throw new SuggestionContextNotFound();
            }

            return null;
        }

        return $context->ask(new ResolveSuggestionContext(
            parentId: $query->parentId,
            hierarchy: $query->hierarchy,
        )) ?? throw new SuggestionContextNotFound();
    }
}
