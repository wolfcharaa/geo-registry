<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\UserInterface\Http\FindAddressById;

use App\Module\AddressRegistry\Capability\AddressDocument;
use App\Module\AddressRegistry\Capability\FindAddressById\FindAddressByIdResult;

final readonly class FindAddressByIdPresenter
{
    /** @return array<string, mixed> */
    public function success(FindAddressByIdResult $result, string $id, int $limit): array
    {
        return [
            'success' => true,
            'count' => count($result->documents),
            'query' => ['query' => $id, 'limit' => $limit],
            'suggestions' => array_map($this->suggestion(...), $result->documents),
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

    /** @return array<string, mixed> */
    private function suggestion(AddressDocument $document): array
    {
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
                'hierarchy_type' => $document->hierarchy,
                'level' => $document->level,
                'score' => 1.0,
                'search_backend' => 'postgres',
                'source' => $document->source,
                'source_id' => $document->sourceId,
                'datamos_global_id' => $document->dataMosGlobalId,
                'unom' => $document->dataMosUnom,
                'nreg' => $document->dataMosNreg,
                'aliases' => [],
                'alias_count' => $document->aliasCount,
                'matched_alias' => null,
                'adm_area' => $document->dataMosAdministrativeArea,
                'district' => $document->dataMosDistrict,
                'kad_n' => $document->cadastralNumbers,
                'kad_zu' => $document->landCadastralNumbers,
            ],
        ];
    }
}
