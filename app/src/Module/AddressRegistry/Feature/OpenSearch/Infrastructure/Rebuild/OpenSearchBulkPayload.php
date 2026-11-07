<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\OpenSearch\Infrastructure\Rebuild;

use App\Module\AddressRegistry\Capability\AddressAlias;
use App\Module\AddressRegistry\Feature\OpenSearch\Capability\RebuildProjection\OpenSearchProjectionDocument;
use App\Module\AddressRegistry\Feature\OpenSearch\Infrastructure\OpenSearchAddressComponentParser;

final readonly class OpenSearchBulkPayload
{
    public function __construct(
        public string $ndjson,
        public int $documents,
    ) {}

    /** @param non-empty-list<OpenSearchProjectionDocument> $documents */
    public static function fromDocuments(array $documents): self
    {
        $ndjson = '';
        $components = new OpenSearchAddressComponentParser();
        foreach ($documents as $document) {
            $ndjson .= json_encode(
                ['index' => ['_id' => $document->id()]],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ) . "\n";
            $ndjson .= json_encode(
                self::source($document, $components),
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ) . "\n";
        }

        return new self($ndjson, count($documents));
    }

    /** @return array<string, mixed> */
    private static function source(
        OpenSearchProjectionDocument $projection,
        OpenSearchAddressComponentParser $components,
    ): array {
        $document = $projection->document;
        $componentSets = $components->componentSets([
            $document->address,
            ...array_map(static fn(AddressAlias $alias): string => $alias->value, $projection->aliases),
        ]);

        return [
            'object_id' => $document->objectId,
            'object_guid' => $document->objectGuid,
            'address' => $document->address,
            'hierarchy_type' => $document->hierarchy->value,
            'region_code' => $projection->regionCode,
            'level' => $document->level,
            'postal_code' => $document->postalCode,
            'okato' => $document->okato,
            'oktmo' => $document->oktmo,
            'geo_lat' => $document->latitude,
            'geo_lon' => $document->longitude,
            'source' => $document->source,
            'source_id' => $document->sourceId,
            'datamos_global_id' => $document->dataMosGlobalId,
            'datamos_unom' => $document->dataMosUnom,
            'datamos_nreg' => $document->dataMosNreg,
            'datamos_adm_area' => $document->dataMosAdministrativeArea,
            'datamos_district' => $document->dataMosDistrict,
            'cadastral_numbers' => $document->cadastralNumbers,
            'land_cadastral_numbers' => $document->landCadastralNumbers,
            'alias_count' => $document->aliasCount,
            'address_street_key' => $componentSets['street_key'],
            'address_house_key' => $componentSets['house_key'],
            'address_building_key' => $componentSets['building_key'],
            'address_structure_key' => $componentSets['structure_key'],
            'aliases' => array_map(self::alias(...), $projection->aliases),
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
