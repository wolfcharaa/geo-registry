<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\UserInterface\Http\SearchAddress;

use Amp\Http\Server\Request;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class SearchAddressRequestMapper
{
    public function __construct(private ValidatorInterface $validator) {}

    public function map(Request $request): SearchAddressRequest
    {
        parse_str($request->getUri()->getQuery(), $parameters);
        $query = $parameters['q'] ?? null;
        if ($query === null || (is_string($query) && trim($query) === '')) {
            throw new InvalidSearchAddressRequest(
                'missing_query',
                'Missing required parameter: q',
                self::defaultQuery(),
            );
        }

        $input = new SearchAddressRequest(
            query: $query,
            hierarchy: $parameters['hier'] ?? 'adm',
            profile: $parameters['profile'] ?? 'search',
            limit: $parameters['limit'] ?? 10,
            regionCode: $parameters['region_code'] ?? null,
        );
        $violations = $this->validator->validate($input);
        if ($violations->count() === 0) {
            return $input;
        }

        $violation = $violations->get(0);
        $field = $violation->getPropertyPath();

        throw new InvalidSearchAddressRequest(
            self::errorCode($field),
            (string) $violation->getMessage(),
            self::safeQuery($parameters),
        );
    }

    /** @return array<string, int|string> */
    private static function defaultQuery(): array
    {
        return ['q' => '', 'hierarchy' => 'adm', 'limit' => 10, 'profile' => 'search'];
    }

    /**
     * @param array<array-key, mixed> $parameters
     * @return array<string, int|string>
     */
    private static function safeQuery(array $parameters): array
    {
        $q = $parameters['q'] ?? null;
        $hierarchy = $parameters['hier'] ?? null;
        $profile = $parameters['profile'] ?? null;
        $limit = $parameters['limit'] ?? null;
        $query = [
            'q' => is_string($q) ? trim($q) : '',
            'hierarchy' => is_string($hierarchy) ? $hierarchy : 'adm',
            'limit' => is_string($limit) && ctype_digit($limit) ? (int) $limit : 10,
            'profile' => is_string($profile) ? $profile : 'search',
        ];
        if (is_string($parameters['region_code'] ?? null) && ctype_digit($parameters['region_code'])) {
            $query['region_code'] = (int) $parameters['region_code'];
        }

        return $query;
    }

    private static function errorCode(string $field): string
    {
        return match ($field) {
            'query' => 'invalid_query',
            'hierarchy' => 'invalid_hierarchy',
            'profile' => 'invalid_profile',
            'limit' => 'invalid_limit',
            'regionCode' => 'invalid_region_code',
            default => 'invalid_query',
        };
    }
}
