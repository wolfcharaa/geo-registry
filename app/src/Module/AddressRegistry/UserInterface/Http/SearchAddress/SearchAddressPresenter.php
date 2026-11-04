<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\UserInterface\Http\SearchAddress;

use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressMatch;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressResult;
use App\Module\AddressRegistry\UserInterface\Http\AddressSuggestionPresenter;

final readonly class SearchAddressPresenter
{
    public function __construct(private AddressSuggestionPresenter $suggestions) {}

    /**
     * @param array<string, int|string> $query
     * @return array<string, mixed>
     */
    public function success(SearchAddressResult $result, array $query): array
    {
        return [
            'success' => true,
            'count' => count($result->matches),
            'query' => $query,
            'suggestions' => array_map($this->suggestion(...), $result->matches),
            'meta' => ['endpoint' => 'search'],
        ];
    }

    /**
     * @param array<string, int|string> $query
     * @return array<string, mixed>
     */
    public function error(string $message, string $code, array $query): array
    {
        return [
            'success' => false,
            'count' => 0,
            'query' => $query,
            'suggestions' => [],
            'meta' => ['endpoint' => 'search'],
            'error' => $message,
            'error_code' => $code,
        ];
    }

    /** @return array<string, mixed> */
    private function suggestion(SearchAddressMatch $match): array
    {
        return $this->suggestions->present(
            document: $match->document,
            score: $match->score,
            aliases: $match->aliases,
            matchedAlias: $match->matchedAlias,
        );
    }
}
