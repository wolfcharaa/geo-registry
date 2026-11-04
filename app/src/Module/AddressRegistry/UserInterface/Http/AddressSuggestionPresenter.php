<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\UserInterface\Http;

use App\Module\AddressRegistry\Capability\AddressAlias;
use App\Module\AddressRegistry\Capability\AddressDocument;

final readonly class AddressSuggestionPresenter
{
    /**
     * @param list<AddressAlias> $aliases
     * @return array<string, mixed>
     */
    public function present(
        AddressDocument $document,
        float $score,
        array $aliases = [],
        ?string $matchedAlias = null,
        string $backend = 'postgres',
    ): array {
        return [
            'value' => $document->address,
            'unrestricted_value' => $document->address,
            'data' => [
                'fias_id' => $document->objectGuid ?? $document->objectId,
                'fias_uuid' => $document->objectGuid,
                'object_id' => $document->objectId,
                'object_guid' => $document->objectGuid,
                'postal_code' => $document->postalCode,
                'okato' => $document->okato,
                'oktmo' => $document->oktmo,
                'geo_lat' => $document->latitude,
                'geo_lon' => $document->longitude,
                'hierarchy_type' => $document->hierarchy->value,
                'level' => $document->level,
                'score' => $score,
                'search_backend' => $backend,
                'source' => $document->source,
                'source_id' => $document->sourceId,
                'datamos_global_id' => $document->dataMosGlobalId,
                'unom' => $document->dataMosUnom,
                'nreg' => $document->dataMosNreg,
                'aliases' => array_map(self::alias(...), $aliases),
                'alias_count' => $document->aliasCount,
                'matched_alias' => $matchedAlias,
                'adm_area' => $document->dataMosAdministrativeArea,
                'district' => $document->dataMosDistrict,
                'kad_n' => $document->cadastralNumbers,
                'kad_zu' => $document->landCadastralNumbers,
            ],
        ];
    }

    /** @return array<string, int|string> */
    private static function alias(AddressAlias $alias): array
    {
        return [
            'value' => $alias->value,
            'kind' => $alias->kind,
            'source' => $alias->source,
            'source_object_type' => $alias->sourceObjectType,
            'source_object_id' => $alias->sourceObjectId,
            'rank' => $alias->rank,
        ];
    }
}
