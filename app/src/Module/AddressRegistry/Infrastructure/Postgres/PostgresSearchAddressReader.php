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
    private const REGION_CONDITION = '{{region_condition}}';

    private const SELECT = <<<'SQL'
WITH input AS NOT MATERIALIZED (
    SELECT websearch_to_tsquery('russian'::regconfig, :query) AS tsquery
),
canonical_fts_matches AS MATERIALIZED (
    SELECT
        s.region_code,
        s.object_id,
        s.hierarchy_type
    FROM address_search s
    CROSS JOIN input
    WHERE s.hierarchy_type = :hierarchy
      AND {{region_condition}}
      AND s.search_vector @@ input.tsquery
),
alias_fts_matches AS MATERIALIZED (
    SELECT
        s.region_code,
        s.object_id,
        s.hierarchy_type,
        a.alias_string,
        a.id AS alias_id,
        GREATEST(
            ts_rank_cd(a.search_vector, input.tsquery, 32),
            similarity(a.alias_string, :query)
        )::double precision AS alias_rank
    FROM address_aliases a
    JOIN address_search s
      ON s.object_id = a.object_id
     AND s.hierarchy_type = a.hierarchy_type
    CROSS JOIN input
    WHERE s.hierarchy_type = :hierarchy
      AND {{region_condition}}
      AND a.search_vector @@ input.tsquery
),
fts_candidate_keys AS MATERIALIZED (
    SELECT region_code, object_id, hierarchy_type
    FROM canonical_fts_matches
    UNION
    SELECT region_code, object_id, hierarchy_type
    FROM alias_fts_matches
),
contains_gate AS MATERIALIZED (
    SELECT count(*) < :limit AS required
    FROM fts_candidate_keys
),
canonical_contains_matches AS MATERIALIZED (
    SELECT
        s.region_code,
        s.object_id,
        s.hierarchy_type
    FROM address_search s
    CROSS JOIN contains_gate
    WHERE contains_gate.required
      AND s.hierarchy_type = :hierarchy
      AND {{region_condition}}
      AND s.address_string ILIKE :contains_query ESCAPE chr(92)
),
alias_contains_matches AS MATERIALIZED (
    SELECT
        s.region_code,
        s.object_id,
        s.hierarchy_type,
        a.alias_string,
        a.id AS alias_id,
        GREATEST(
            ts_rank_cd(a.search_vector, input.tsquery, 32),
            similarity(a.alias_string, :query)
        )::double precision AS alias_rank
    FROM address_aliases a
    JOIN address_search s
      ON s.object_id = a.object_id
     AND s.hierarchy_type = a.hierarchy_type
    CROSS JOIN input
    CROSS JOIN contains_gate
    WHERE contains_gate.required
      AND s.hierarchy_type = :hierarchy
      AND {{region_condition}}
      AND a.alias_string ILIKE :contains_query ESCAPE chr(92)
),
candidate_keys AS MATERIALIZED (
    SELECT region_code, object_id, hierarchy_type
    FROM fts_candidate_keys
    UNION
    SELECT region_code, object_id, hierarchy_type
    FROM canonical_contains_matches
    UNION
    SELECT region_code, object_id, hierarchy_type
    FROM alias_contains_matches
),
alias_matches AS MATERIALIZED (
    SELECT DISTINCT ON (region_code, object_id, hierarchy_type)
        region_code,
        object_id,
        hierarchy_type,
        alias_string,
        alias_rank
    FROM (
        SELECT * FROM alias_fts_matches
        UNION ALL
        SELECT * FROM alias_contains_matches
    ) matches
    ORDER BY
        region_code,
        object_id,
        hierarchy_type,
        alias_rank DESC,
        alias_string ASC,
        alias_id ASC
),
limited AS MATERIALIZED (
    SELECT
        s.object_id,
        s.object_guid,
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
        s.search_vector,
        matched.alias_string AS matched_alias,
        GREATEST(
            ts_rank_cd(s.search_vector, input.tsquery, 32),
            similarity(s.address_string, :query),
            COALESCE(matched.alias_rank, 0)
        )::double precision AS search_score,
        lower(s.address_string) = lower(:query) AS exact_match
    FROM candidate_keys candidates
    JOIN address_search s
      ON s.region_code = candidates.region_code
     AND s.object_id = candidates.object_id
     AND s.hierarchy_type = candidates.hierarchy_type
    CROSS JOIN input
    LEFT JOIN alias_matches matched
      ON matched.region_code = candidates.region_code
     AND matched.object_id = candidates.object_id
     AND matched.hierarchy_type = candidates.hierarchy_type
    ORDER BY
        exact_match DESC,
        search_score DESC,
        s.level DESC,
        s.address_string ASC,
        s.object_id ASC
    LIMIT :limit
)
SELECT
    limited.object_id,
    limited.object_guid::text AS object_guid,
    limited.address_string,
    limited.hierarchy_type,
    limited.level,
    limited.postal_code,
    limited.okato,
    limited.oktmo,
    limited.geo_lat,
    limited.geo_lon,
    limited.address_source,
    limited.address_source_id,
    limited.datamos_global_id,
    limited.datamos_unom,
    limited.datamos_nreg,
    limited.datamos_adm_area,
    limited.datamos_district,
    limited.datamos_kad_n,
    limited.datamos_kad_zu,
    aliases.address_alias_count,
    aliases.address_aliases::text AS address_aliases,
    limited.matched_alias,
    limited.search_score
FROM limited
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
    WHERE a.object_id = limited.object_id
      AND a.hierarchy_type = limited.hierarchy_type
) aliases ON true
ORDER BY
    limited.exact_match DESC,
    limited.search_score DESC,
    limited.level DESC,
    limited.address_string ASC,
    limited.object_id ASC
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
            'contains_query' => $this->containsPattern($criteria->query),
            'hierarchy' => $criteria->hierarchy->value,
            'limit' => $criteria->limit,
        ];
        $regionCondition = 'TRUE';
        if ($criteria->regionCode !== null) {
            $regionCondition = 's.region_code = :region_code';
            $parameters['region_code'] = $criteria->regionCode;
        }

        try {
            $result = $this->postgres->execute(
                str_replace(self::REGION_CONDITION, $regionCondition, self::SELECT),
                $parameters,
            );
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

    private function containsPattern(string $query): string
    {
        return '%' . str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $query,
        ) . '%';
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
