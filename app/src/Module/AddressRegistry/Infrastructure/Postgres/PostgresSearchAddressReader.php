<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\Postgres;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Postgres\PostgresConnectionPool;
use App\Module\AddressRegistry\Capability\AddressAlias;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressCriteria;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressMatch;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressPort;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressUnavailable;
use RuntimeException;
use Throwable;

final readonly class PostgresSearchAddressReader implements SearchAddressPort
{
    private const SELECT = <<<'SQL'
WITH input AS (
    SELECT websearch_to_tsquery('russian'::regconfig, :query) AS tsquery
)
SELECT
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
    s.datamos_kad_n,
    s.datamos_kad_zu,
    aliases.address_alias_count,
    aliases.address_aliases::text AS address_aliases,
    matched.alias_string AS matched_alias,
    GREATEST(
        ts_rank_cd(s.search_vector, input.tsquery, 32),
        similarity(s.address_string, :query),
        COALESCE(matched.alias_rank, 0)
    )::double precision AS search_score
FROM address_search s
CROSS JOIN input
LEFT JOIN LATERAL (
    SELECT
        a.alias_string,
        GREATEST(
            ts_rank_cd(a.search_vector, input.tsquery, 32),
            similarity(a.alias_string, :query)
        )::double precision AS alias_rank
    FROM address_aliases a
    WHERE a.object_id = s.object_id
      AND a.hierarchy_type = s.hierarchy_type
      AND (
          a.search_vector @@ input.tsquery
          OR similarity(a.alias_string, :query) >= 0.3
      )
    ORDER BY alias_rank DESC, a.alias_string ASC, a.id ASC
    LIMIT 1
) matched ON true
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
WHERE s.hierarchy_type = :hierarchy
  AND %s
  AND (
      s.search_vector @@ input.tsquery
      OR similarity(s.address_string, :query) >= 0.3
      OR matched.alias_string IS NOT NULL
  )
ORDER BY
    (lower(s.address_string) = lower(:query)) DESC,
    search_score DESC,
    s.level DESC,
    s.address_string ASC,
    s.object_id ASC
LIMIT :limit
SQL;

    public function __construct(
        private PostgresConnectionPool $postgres,
        private PostgresAddressDocumentHydrator $hydrator,
    ) {}

    public function search(SearchAddressCriteria $criteria, Cancellation $cancellation): array
    {
        $cancellation->throwIfRequested();
        if ($criteria->query === '') {
            return [];
        }

        $parameters = [
            'query' => $criteria->query,
            'hierarchy' => $criteria->hierarchy->value,
            'limit' => $criteria->limit,
        ];
        $regionCondition = 'TRUE';
        if ($criteria->regionCode !== null) {
            $regionCondition = 's.region_code = :region_code';
            $parameters['region_code'] = $criteria->regionCode;
        }

        try {
            $result = $this->postgres->execute(sprintf(self::SELECT, $regionCondition), $parameters);
            $matches = [];

            while (($row = $result->fetchRow()) !== null) {
                $cancellation->throwIfRequested();
                $matches[] = new SearchAddressMatch(
                    document: $this->hydrator->hydrate($row),
                    score: $this->score($row),
                    aliases: $this->aliases($row['address_aliases'] ?? null),
                    matchedAlias: $this->hydrator->nullableString($row, 'matched_alias'),
                );
            }

            $cancellation->throwIfRequested();

            return $matches;
        } catch (CancelledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new SearchAddressUnavailable($exception);
        }
    }

    /** @param array<array-key, mixed> $row */
    private function score(array $row): float
    {
        $score = $this->hydrator->nullableFloat($row, 'search_score');
        if ($score === null) {
            throw new RuntimeException('PostgreSQL field `search_score` must be numeric.');
        }

        return $score;
    }

    /** @return list<AddressAlias> */
    private function aliases(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        }
        if (!is_array($value)) {
            throw new RuntimeException('PostgreSQL field `address_aliases` must be a JSON list.');
        }

        $aliases = [];
        foreach ($value as $alias) {
            if (!is_array($alias)) {
                throw new RuntimeException('PostgreSQL address alias must be a JSON object.');
            }
            $aliases[] = new AddressAlias(
                value: $this->string($alias, 'value'),
                kind: $this->string($alias, 'kind'),
                source: $this->string($alias, 'source'),
                sourceObjectType: $this->string($alias, 'source_object_type'),
                sourceObjectId: $this->hydrator->integer($alias, 'source_object_id'),
                rank: $this->hydrator->integer($alias, 'rank'),
            );
        }

        return $aliases;
    }

    /** @param array<array-key, mixed> $row */
    private function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException("PostgreSQL field `{$field}` must be a string.");
        }

        return $value;
    }
}
