<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\UserInterface\Http\SuggestAddress;

use App\Module\AddressRegistry\Capability\ProgressiveAddressSuggestion;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressMatch;
use App\Module\AddressRegistry\Capability\SuggestAddress\SuggestAddressResult;
use App\Module\AddressRegistry\Capability\SuggestionDistrict;
use App\Module\AddressRegistry\Capability\SuggestionStage;
use App\Module\AddressRegistry\UserInterface\Http\AddressSuggestionPresenter;

final readonly class SuggestAddressPresenter
{
    public function __construct(private AddressSuggestionPresenter $documents) {}

    /**
     * @param array<string, int|string|null> $query
     * @return array<string, mixed>
     */
    public function success(
        SuggestAddressResult $result,
        array $query,
        SuggestionStage $stage,
    ): array {
        return [
            'success' => true,
            'count' => $result->count(),
            'query' => $query,
            'suggestions' => [
                ...array_map(self::progressive(...), $result->progressiveSuggestions),
                ...array_map($this->document(...), $result->searchMatches),
            ],
            'meta' => [
                'endpoint' => 'suggest',
                'contract_version' => 2,
                'stage' => $stage->value,
            ],
        ];
    }

    /**
     * @param array<string, int|string|null> $query
     * @return array<string, mixed>
     */
    public function error(string $message, string $errorCode, array $query): array
    {
        return [
            'success' => false,
            'count' => 0,
            'query' => $query,
            'suggestions' => [],
            'meta' => ['endpoint' => 'suggest', 'contract_version' => 2],
            'error' => $message,
            'error_code' => $errorCode,
        ];
    }

    /** @return array<string, mixed> */
    private static function progressive(ProgressiveAddressSuggestion $suggestion): array
    {
        $value = $suggestion->displayValue();

        return [
            'value' => $value,
            'unrestricted_value' => $value,
            'data' => [
                'fias_id' => $suggestion->objectGuid,
                'fias_uuid' => $suggestion->objectGuid,
                'object_id' => $suggestion->objectId,
                'object_guid' => $suggestion->objectGuid,
                'name' => $suggestion->name,
                'hierarchy_type' => $suggestion->hierarchy->value,
                'level' => $suggestion->level,
                'district' => $suggestion->district?->name,
                'context' => [
                    'parent_id' => $suggestion->objectId,
                    'hierarchy' => $suggestion->hierarchy->value,
                    'district' => self::district($suggestion->district),
                ],
                'next_stage' => $suggestion->nextStage->value,
                'ready_for_search' => $suggestion->readyForSearch,
            ],
        ];
    }

    /** @return array{object_id: int, name: string}|null */
    private static function district(?SuggestionDistrict $district): ?array
    {
        return $district === null ? null : [
            'object_id' => $district->objectId,
            'name' => $district->name,
        ];
    }

    /** @return array<string, mixed> */
    private function document(SearchAddressMatch $match): array
    {
        return $this->documents->present(
            document: $match->document,
            score: $match->score,
            aliases: $match->aliases,
            matchedAlias: $match->matchedAlias,
            backend: $match->backend,
        );
    }
}
