<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\ReverseGeocode\Capability;

use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddress;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressMatch;
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
        if ($location->address === null) {
            return ReverseGeocodeResult::fallback($location);
        }

        $matches = $context->ask(new SearchAddress(
            query: $location->address->query,
            hierarchy: $query->hierarchy,
            profile: SearchProfile::Search,
            limit: $query->limit,
        ))->matches;
        $matches = array_values(array_filter(
            $matches,
            static fn(SearchAddressMatch $match): bool => self::matchesAddress(
                $match,
                $location->address,
            ),
        ));

        if ($matches === []) {
            return ReverseGeocodeResult::fallback($location);
        }

        foreach ($matches as $match) {
            $this->coordinates->publishIfMissing($match->document, $location, $context);
        }

        return ReverseGeocodeResult::matched($matches);
    }

    private static function matchesAddress(
        SearchAddressMatch $match,
        ReverseGeocodeAddress $address,
    ): bool {
        return self::hasExactHouseNumber($match->document->address, $address->houseNumber)
            && self::containsRoute($match->document->address, $address->route);
    }

    private static function hasExactHouseNumber(string $value, string $houseNumber): bool
    {
        $houseNumber = preg_quote(self::normalizeHouseNumber($houseNumber), '/');
        $houseNumber = str_replace(' ', '\\s*', $houseNumber);

        return preg_match(
            '/(?:^|[,\s])(?:д|дом)\.?\s*' . $houseNumber
            . '(?=$|[, ]|\s+(?:к|корп|корпус|стр|строение|с|вл|владение|помещ|помещение)\.?)/iu',
            $value,
        ) === 1;
    }

    private static function containsRoute(string $value, string $route): bool
    {
        $route = str_replace(' ', '\\s+', preg_quote(trim($route), '/'));

        return preg_match('/(?:^|[,\s])' . $route . '(?=$|[,])/iu', $value) === 1;
    }

    private static function normalizeHouseNumber(string $houseNumber): string
    {
        $houseNumber = (string) preg_replace('/([а-яА-Яa-zA-Z])(\d)/u', '$1 $2', $houseNumber);

        return (string) preg_replace('/(\d)([а-яА-Яa-zA-Z])/u', '$1 $2', $houseNumber);
    }
}
