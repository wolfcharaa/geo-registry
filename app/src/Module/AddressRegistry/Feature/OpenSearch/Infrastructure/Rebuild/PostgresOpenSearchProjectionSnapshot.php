<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\OpenSearch\Infrastructure\Rebuild;

use Amp\Postgres\PostgresTransaction;
use App\Module\AddressRegistry\Capability\AddressAlias;
use App\Module\AddressRegistry\Feature\OpenSearch\Capability\OpenSearchProjectionFailed;
use App\Module\AddressRegistry\Feature\OpenSearch\Capability\RebuildProjection\OpenSearchProjectionDocument;
use App\Module\AddressRegistry\Infrastructure\Postgres\PostgresAddressDocumentHydrator;
use JsonException;

final readonly class PostgresOpenSearchProjectionSnapshot
{
    private const BATCH = <<<'SQL'
SELECT
    s.id,
    s.region_code,
    s.object_id,
    s.object_guid::text AS object_guid,
    s.address_string,
    s.hierarchy_type,
    s.level,
    s.postal_code,
    s.okato,
    s.oktmo,
    s.geo_lat,
    s.geo_lon,
    s.address_source,
    s.address_source_id,
    s.datamos_global_id,
    s.datamos_unom,
    s.datamos_nreg,
    s.datamos_adm_area,
    s.datamos_district,
    s.datamos_kad_n::text AS datamos_kad_n,
    s.datamos_kad_zu::text AS datamos_kad_zu,
    aliases.address_alias_count,
    aliases.address_aliases::text AS address_aliases
FROM address_search s
LEFT JOIN LATERAL (
    SELECT
        count(*)::integer AS address_alias_count,
        COALESCE(
            jsonb_agg(
                jsonb_build_object(
                    'value', a.alias_string,
                    'kind', a.alias_kind,
                    'source', a.source,
                    'source_object_type', a.source_object_type,
                    'source_object_id', a.source_object_id,
                    'rank', a.source_address_rank
                )
                ORDER BY a.source_address_rank ASC, a.alias_string ASC, a.id ASC
            ),
            '[]'::jsonb
        ) AS address_aliases
    FROM address_aliases a
    WHERE a.object_id = s.object_id
      AND a.hierarchy_type = s.hierarchy_type
) aliases ON true
WHERE (s.region_code, s.id) > (:last_region_code, :last_row_id)
ORDER BY s.region_code ASC, s.id ASC
LIMIT :batch_size
SQL;

    public function __construct(
        private PostgresTransaction $transaction,
        private PostgresAddressDocumentHydrator $hydrator,
        public OpenSearchProjectionManifest $manifest,
    ) {}

    public function nextBatch(int $lastRegionCode, int $lastRowId, int $batchSize): ?OpenSearchProjectionBatch
    {
        $result = $this->transaction->execute(self::BATCH, [
            'last_region_code' => $lastRegionCode,
            'last_row_id' => $lastRowId,
            'batch_size' => $batchSize,
        ]);
        $documents = [];
        $nextRegionCode = $lastRegionCode;
        $nextRowId = $lastRowId;

        while (($row = $result->fetchRow()) !== null) {
            $nextRegionCode = $this->hydrator->integer($row, 'region_code');
            $nextRowId = $this->hydrator->integer($row, 'id');
            $aliases = $this->aliases($row['address_aliases'] ?? null);
            $documents[] = new OpenSearchProjectionDocument(
                regionCode: $nextRegionCode,
                document: $this->hydrator->hydrate($row),
                aliases: $aliases,
            );
        }

        return $documents === []
            ? null
            : new OpenSearchProjectionBatch($documents, $nextRegionCode, $nextRowId);
    }

    public function close(): void
    {
        if ($this->transaction->isActive()) {
            $this->transaction->commit();
        }
    }

    public function abort(): void
    {
        if ($this->transaction->isActive()) {
            $this->transaction->rollback();
        }
    }

    /** @return list<AddressAlias> */
    private function aliases(mixed $value): array
    {
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                throw new OpenSearchProjectionFailed('PostgreSQL returned invalid address alias JSON.', previous: $error);
            }
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new OpenSearchProjectionFailed('PostgreSQL address aliases must be a JSON list.');
        }

        $aliases = [];
        foreach ($value as $alias) {
            if (!is_array($alias)) {
                throw new OpenSearchProjectionFailed('PostgreSQL address alias must be a JSON object.');
            }
            $aliases[] = new AddressAlias(
                value: self::string($alias, 'value'),
                kind: self::string($alias, 'kind'),
                source: self::string($alias, 'source'),
                sourceObjectType: self::string($alias, 'source_object_type'),
                sourceObjectId: $this->hydrator->integer($alias, 'source_object_id'),
                rank: $this->hydrator->integer($alias, 'rank'),
            );
        }

        return $aliases;
    }

    /** @param array<array-key, mixed> $values */
    private static function string(array $values, string $field): string
    {
        $value = $values[$field] ?? null;
        if (!is_string($value)) {
            throw new OpenSearchProjectionFailed("PostgreSQL address alias field `{$field}` must be a string.");
        }

        return $value;
    }
}
