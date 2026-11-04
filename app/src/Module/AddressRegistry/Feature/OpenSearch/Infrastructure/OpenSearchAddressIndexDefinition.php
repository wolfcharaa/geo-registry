<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\OpenSearch\Infrastructure;

final class OpenSearchAddressIndexDefinition
{
    /** @return array<string, mixed> */
    public static function create(
        int $numberOfShards = 1,
        int $numberOfReplicas = 0,
        string $refreshInterval = '1s',
    ): array {
        return [
            'settings' => [
                'number_of_shards' => $numberOfShards,
                'number_of_replicas' => $numberOfReplicas,
                'refresh_interval' => $refreshInterval,
            ],
            'mappings' => [
                'dynamic' => 'strict',
                'properties' => [
                    'object_id' => ['type' => 'long'],
                    'object_guid' => ['type' => 'keyword'],
                    'address' => [
                        'type' => 'text',
                        'analyzer' => 'russian',
                        'fields' => ['keyword' => ['type' => 'keyword', 'ignore_above' => 2048]],
                    ],
                    'hierarchy_type' => ['type' => 'keyword'],
                    'region_code' => ['type' => 'short'],
                    'level' => ['type' => 'short'],
                    'postal_code' => ['type' => 'keyword'],
                    'okato' => ['type' => 'keyword'],
                    'oktmo' => ['type' => 'keyword'],
                    'geo_lat' => ['type' => 'double'],
                    'geo_lon' => ['type' => 'double'],
                    'source' => ['type' => 'keyword'],
                    'source_id' => ['type' => 'keyword'],
                    'datamos_global_id' => ['type' => 'long'],
                    'datamos_unom' => ['type' => 'long'],
                    'datamos_nreg' => ['type' => 'long'],
                    'datamos_adm_area' => ['type' => 'keyword'],
                    'datamos_district' => ['type' => 'keyword'],
                    'cadastral_numbers' => ['type' => 'keyword'],
                    'land_cadastral_numbers' => ['type' => 'keyword'],
                    'alias_count' => ['type' => 'integer'],
                    'aliases' => [
                        'properties' => [
                            'value' => ['type' => 'text', 'analyzer' => 'russian'],
                            'kind' => ['type' => 'keyword'],
                            'source' => ['type' => 'keyword'],
                            'source_object_type' => ['type' => 'keyword'],
                            'source_object_id' => ['type' => 'long'],
                            'rank' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function __construct() {}
}
