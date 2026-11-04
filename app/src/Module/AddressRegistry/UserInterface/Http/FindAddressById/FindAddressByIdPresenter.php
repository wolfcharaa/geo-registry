<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\UserInterface\Http\FindAddressById;

use App\Module\AddressRegistry\Capability\FindAddressById\FindAddressByIdResult;
use App\Module\AddressRegistry\UserInterface\Http\AddressSuggestionPresenter;

final readonly class FindAddressByIdPresenter
{
    public function __construct(private AddressSuggestionPresenter $suggestions) {}

    /** @return array<string, mixed> */
    public function success(FindAddressByIdResult $result, string $id, int $limit): array
    {
        return [
            'success' => true,
            'count' => count($result->documents),
            'query' => ['query' => $id, 'limit' => $limit],
            'suggestions' => array_map(
                fn($document): array => $this->suggestions->present($document, 1.0),
                $result->documents,
            ),
            'meta' => ['endpoint' => 'find-by-id'],
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function error(string $message, string $code, array $query = []): array
    {
        return [
            'success' => false,
            'count' => 0,
            'query' => $query,
            'suggestions' => [],
            'meta' => ['endpoint' => 'find-by-id'],
            'error' => $message,
            'error_code' => $code,
        ];
    }
}
