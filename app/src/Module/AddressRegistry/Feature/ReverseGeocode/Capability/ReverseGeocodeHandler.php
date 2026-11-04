<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\ReverseGeocode\Capability;

use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddress;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchProfile;
use Fedor\MessageBus\Attribute\QueryHandler;
use Fedor\MessageBus\Context\MessageContextInterface;

#[QueryHandler(
    message: ReverseGeocode::class,
    bindingId: 'address-registry.reverse-geocode.v1',
    ownerKind: 'feature',
    ownerId: 'AddressRegistry.ReverseGeocode',
)]
final readonly class ReverseGeocodeHandler
{
    public function __construct(
        private ReverseGeocodePort $locator,
        private MissingAddressCoordinatesPublisher $coordinates,
    ) {}

    public function __invoke(ReverseGeocode $query, MessageContextInterface $context): ReverseGeocodeResult
    {
        $location = $this->locator->locate(
            $query->latitude,
            $query->longitude,
            $context->cancellation(),
        );
        if ($location === null) {
            return ReverseGeocodeResult::empty();
        }

        $matches = $context->ask(new SearchAddress(
            query: $location->displayName,
            hierarchy: $query->hierarchy,
            profile: SearchProfile::Search,
            limit: $query->limit,
        ))->matches;

        if ($matches === []) {
            return ReverseGeocodeResult::fallback($location);
        }

        $this->coordinates->publishIfMissing($matches[0]->document, $location, $context);

        return ReverseGeocodeResult::matched($matches);
    }
}
