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
    private const array ROUTE_TYPES = [
        'аллея',
        'бульвар',
        'дорога',
        'линия',
        'набережная',
        'переулок',
        'площадь',
        'проезд',
        'проспект',
        'тракт',
        'тупик',
        'улица',
        'шоссе',
    ];

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
        if (trim($route) === '') {
            return false;
        }

        foreach (self::routeVariants($route) as $variant) {
            $pattern = str_replace(' ', '\\s+', preg_quote($variant, '/'));
            if (preg_match('/(?:^|[,\s])' . $pattern . '(?=$|[,])/iu', $value) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return non-empty-list<string> */
    private static function routeVariants(string $route): array
    {
        $route = trim((string) preg_replace('/\s+/u', ' ', $route));
        $parts = explode(' ', $route);
        if (count($parts) < 2) {
            return [$route];
        }

        $first = mb_strtolower($parts[0]);
        $lastIndex = count($parts) - 1;
        $last = mb_strtolower($parts[$lastIndex]);
        if (in_array($first, self::ROUTE_TYPES, true)) {
            return [$route, implode(' ', [...array_slice($parts, 1), $parts[0]])];
        }
        if (in_array($last, self::ROUTE_TYPES, true)) {
            return [$route, implode(' ', [$parts[$lastIndex], ...array_slice($parts, 0, $lastIndex)])];
        }

        return [$route];
    }

    private static function normalizeHouseNumber(string $houseNumber): string
    {
        $houseNumber = (string) preg_replace('/([а-яА-Яa-zA-Z])(\d)/u', '$1 $2', $houseNumber);

        return (string) preg_replace('/(\d)([а-яА-Яa-zA-Z])/u', '$1 $2', $houseNumber);
    }
}
