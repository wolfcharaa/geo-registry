<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\Postgres;

use function Amp\async;

use Amp\Future;
use Amp\Postgres\PostgresConnectionPool;
use App\Module\AddressRegistry\Capability\Integrity\GarIntegrityChecker;
use App\Module\AddressRegistry\Capability\Integrity\GarIntegrityCheckResult;
use App\Module\AddressRegistry\Capability\Integrity\GarIntegrityReport;
use RuntimeException;
use Throwable;

final readonly class PostgresGarIntegrityChecker implements GarIntegrityChecker
{
    private const int DEEP_CONCURRENCY = 2;

    public function __construct(private PostgresConnectionPool $postgres) {}

    public function check(array $regions, bool $deep): GarIntegrityReport
    {
        $definitions = [...$this->quickChecks($regions), ...($deep ? $this->deepChecks($regions) : [])];
        /** @var list<GarIntegrityCheckResult> $results */
        $results = [];
        foreach (array_chunk($definitions, $deep ? self::DEEP_CONCURRENCY : 1) as $chunk) {
            /** @var list<Future<GarIntegrityCheckResult>> $futures */
            $futures = [];
            foreach ($chunk as $definition) {
                $futures[] = async(fn(): GarIntegrityCheckResult => $this->run($definition));
            }
            foreach ($futures as $future) {
                /** @var GarIntegrityCheckResult $result */
                $result = $future->await();
                $results[] = $result;
            }
        }

        return new GarIntegrityReport($regions, $deep, $results);
    }

    /**
     * @param non-empty-list<int> $regions
     * @return non-empty-list<array{name: string, sql: string}>
     */
    private function quickChecks(array $regions): array
    {
        $values = self::regionValues($regions);

        return [
            [
                'name' => 'latest GAR import is complete',
                'sql' => <<<'SQL'
WITH latest AS (
    SELECT * FROM gar_import_operations ORDER BY id DESC LIMIT 1
)
SELECT (CASE WHEN EXISTS (
    SELECT 1
    FROM latest
    WHERE status = 'completed'
      AND files_total > 0
      AND files_completed = files_total
      AND rows_processed = rows_merged
) THEN 0 ELSE 1 END)::bigint AS issues
SQL,
            ],
            [
                'name' => 'latest GAR import has no incomplete files',
                'sql' => <<<'SQL'
WITH latest AS (
    SELECT id FROM gar_import_operations ORDER BY id DESC LIMIT 1
)
SELECT count(*)::bigint AS issues
FROM gar_import_files files
JOIN latest ON latest.id = files.operation_id
WHERE files.status <> 'completed'
SQL,
            ],
            [
                'name' => 'latest address_search rebuild parity is complete',
                'sql' => <<<'SQL'
WITH latest AS (
    SELECT * FROM address_search_rebuild_operations ORDER BY id DESC LIMIT 1
)
SELECT (CASE WHEN EXISTS (
    SELECT 1
    FROM latest
    WHERE status = 'completed'
      AND phase = 'completed'
      AND tasks_total > 0
      AND tasks_completed = tasks_total
      AND shadow_rows > 0
      AND missing_rows = 0
      AND duplicate_rows = 0
      AND invalid_region_rows = 0
) THEN 0 ELSE 1 END)::bigint AS issues
SQL,
            ],
            [
                'name' => 'selected region rebuild checkpoints are complete',
                'sql' => <<<SQL
WITH requested(region_code) AS (VALUES {$values}),
hierarchies(hierarchy_type) AS (VALUES ('adm'::varchar(3)), ('mun'::varchar(3))),
latest AS (SELECT id FROM address_search_rebuild_operations ORDER BY id DESC LIMIT 1)
SELECT count(*)::bigint AS issues
FROM requested
CROSS JOIN hierarchies
LEFT JOIN latest ON true
LEFT JOIN address_search_rebuild_regions checkpoint
  ON checkpoint.operation_id = latest.id
 AND checkpoint.region_code = requested.region_code
 AND checkpoint.hierarchy_type = hierarchies.hierarchy_type
WHERE checkpoint.operation_id IS NULL
   OR checkpoint.status <> 'completed'
   OR checkpoint.scanned_rows <> checkpoint.source_rows
SQL,
            ],
            [
                'name' => 'address_search is LIST-partitioned',
                'sql' => <<<'SQL'
SELECT (CASE WHEN EXISTS (
    SELECT 1 FROM pg_class WHERE oid = 'address_search'::regclass AND relkind = 'p'
) THEN 0 ELSE 1 END)::bigint AS issues
SQL,
            ],
            [
                'name' => 'selected region hierarchy projections are non-empty',
                'sql' => <<<SQL
WITH requested(region_code) AS (VALUES {$values}),
hierarchies(hierarchy_type) AS (VALUES ('adm'::varchar(3)), ('mun'::varchar(3)))
SELECT count(*)::bigint AS issues
FROM requested
CROSS JOIN hierarchies
WHERE NOT EXISTS (
    SELECT 1
    FROM address_search search
    WHERE search.region_code = requested.region_code
      AND search.hierarchy_type = hierarchies.hierarchy_type
)
SQL,
            ],
            [
                'name' => 'selected regions route to physical partitions',
                'sql' => <<<SQL
WITH requested(region_code) AS (VALUES {$values})
SELECT count(*)::bigint AS issues
FROM requested
LEFT JOIN LATERAL (
    SELECT search.tableoid
    FROM address_search search
    WHERE search.region_code = requested.region_code
    LIMIT 1
) sample ON true
WHERE sample.tableoid IS NULL
   OR NOT EXISTS (
       SELECT 1
       FROM pg_inherits inheritance
       WHERE inheritance.inhparent = 'address_search'::regclass
         AND inheritance.inhrelid = sample.tableoid
   )
SQL,
            ],
        ];
    }

    /**
     * @param non-empty-list<int> $regions
     * @return non-empty-list<array{name: string, sql: string}>
     */
    private function deepChecks(array $regions): array
    {
        $regionList = implode(', ', $regions);

        return [
            [
                'name' => 'selected projections have no duplicate identities',
                'sql' => <<<SQL
SELECT count(*)::bigint AS issues
FROM (
    SELECT region_code, object_id, hierarchy_type
    FROM address_search
    WHERE region_code IN ({$regionList})
    GROUP BY region_code, object_id, hierarchy_type
    HAVING count(*) > 1
) duplicates
SQL,
            ],
            [
                'name' => 'selected projections have valid required fields',
                'sql' => <<<SQL
SELECT (
    count(*) FILTER (
        WHERE object_guid IS NULL AND coalesce(address_source, 'gar') <> 'datamos'
    )
    + count(*) FILTER (WHERE btrim(address_string) = '')
    + count(*) FILTER (
        WHERE address_source LIKE '%%datamos%%' AND datamos_global_id IS NULL
    )
)::bigint AS issues
FROM address_search
WHERE region_code IN ({$regionList})
SQL,
            ],
            [
                'name' => 'selected GAR projections reference canonical objects',
                'sql' => <<<SQL
SELECT count(*)::bigint AS issues
FROM address_search search
WHERE search.region_code IN ({$regionList})
  AND coalesce(search.address_source, 'gar') <> 'datamos'
  AND NOT EXISTS (
      SELECT 1 FROM reestr_objects object WHERE object.id = search.object_id
  )
SQL,
            ],
            [
                'name' => 'selected GAR projections keep active GAR region ownership',
                'sql' => <<<SQL
SELECT count(*)::bigint AS issues
FROM address_search search
WHERE search.region_code IN ({$regionList})
  AND coalesce(search.address_source, 'gar') <> 'datamos'
  AND NOT EXISTS (
      SELECT 1
      FROM administrative_reestr_hierarchy hierarchy
      WHERE hierarchy.object_id = search.object_id
        AND hierarchy.region_code = search.region_code
        AND hierarchy.is_active = true
        AND hierarchy.end_date > CURRENT_DATE
  )
SQL,
            ],
            [
                'name' => 'active selected hierarchies exist in address_search',
                'sql' => <<<SQL
WITH source AS (
    SELECT
        hierarchy.region_code::smallint AS region_code,
        'adm'::varchar(3) AS hierarchy_type,
        hierarchy.object_id
    FROM administrative_reestr_hierarchy hierarchy
    WHERE hierarchy.region_code IN ({$regionList})
      AND hierarchy.is_active = true
      AND hierarchy.end_date > CURRENT_DATE

    UNION ALL

    SELECT
        region_hierarchy.region_code::smallint AS region_code,
        'mun'::varchar(3) AS hierarchy_type,
        hierarchy.object_id
    FROM municipal_reestr_hierarchy hierarchy
    JOIN administrative_reestr_hierarchy region_hierarchy
      ON region_hierarchy.object_id = hierarchy.object_id
     AND region_hierarchy.region_code IN ({$regionList})
     AND region_hierarchy.is_active = true
     AND region_hierarchy.end_date > CURRENT_DATE
    WHERE hierarchy.is_active = true
      AND hierarchy.end_date > CURRENT_DATE
)
SELECT count(*)::bigint AS issues
FROM source
WHERE NOT EXISTS (
    SELECT 1
    FROM address_search search
    WHERE search.region_code = source.region_code
      AND search.object_id = source.object_id
      AND search.hierarchy_type = source.hierarchy_type
)
SQL,
            ],
            [
                'name' => 'address aliases reference existing projections',
                'sql' => <<<'SQL'
SELECT count(*)::bigint AS issues
FROM address_aliases alias
WHERE NOT EXISTS (
    SELECT 1
    FROM address_search search
    WHERE search.object_id = alias.object_id
      AND search.hierarchy_type = alias.hierarchy_type
)
SQL,
            ],
        ];
    }

    /** @param array{name: string, sql: string} $definition */
    private function run(array $definition): GarIntegrityCheckResult
    {
        try {
            $row = $this->postgres->query($definition['sql'])->fetchRow();
            $issues = $row['issues'] ?? null;
            if (is_string($issues) && ctype_digit($issues)) {
                $issues = (int) $issues;
            }
            if (!is_int($issues) || $issues < 0) {
                throw new RuntimeException('PostgreSQL returned an invalid GAR integrity result.');
            }

            return new GarIntegrityCheckResult($definition['name'], $issues);
        } catch (Throwable) {
            return new GarIntegrityCheckResult($definition['name'], null);
        }
    }

    /** @param non-empty-list<int> $regions */
    private static function regionValues(array $regions): string
    {
        return implode(', ', array_map(
            static fn(int $region): string => "({$region}::smallint)",
            $regions,
        ));
    }
}
