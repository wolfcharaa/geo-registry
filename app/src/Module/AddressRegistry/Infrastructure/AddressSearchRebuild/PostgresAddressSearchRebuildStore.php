<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\AddressSearchRebuild;

use App\Module\AddressRegistry\Capability\RebuildAddressSearch\AddressSearchRebuildFailed;
use App\Module\AddressRegistry\Capability\RebuildAddressSearch\RebuildAddressSearchResult;
use PgSql\Connection;
use PgSql\Result;
use Throwable;

final readonly class PostgresAddressSearchRebuildStore
{
    private const LOCK_KEY = 'geo-registry:address-search-rebuild';

    public function __construct(
        private Connection $connection,
    ) {}

    public function acquireLock(): void
    {
        $row = $this->row($this->queryParams(
            'SELECT pg_try_advisory_lock(hashtext($1)) AS locked',
            [self::LOCK_KEY],
        ));
        if (($row['locked'] ?? null) !== 't') {
            throw new AddressSearchRebuildFailed('Another address_search rebuild is already running.');
        }
    }

    public function releaseLock(): void
    {
        $this->queryParams('SELECT pg_advisory_unlock(hashtext($1))', [self::LOCK_KEY]);
    }

    public function startOperation(int $batchSize, bool $restart): AddressSearchRebuildOperationState
    {
        $this->query('BEGIN');

        try {
            $abandonedTables = [];
            if ($restart) {
                $result = $this->query(<<<'SQL'
SELECT shadow_table
FROM address_search_rebuild_operations
WHERE status IN ('running', 'ready', 'failed')
FOR UPDATE
SQL);
                while (($row = $this->optionalRow($result)) !== null) {
                    $abandonedTables[] = self::string($row, 'shadow_table');
                }
                $this->query(<<<'SQL'
UPDATE address_search_rebuild_operations
SET status = 'abandoned', updated_at = now(), failed_at = now(),
    error_message = 'Operation explicitly restarted by CLI operator.'
WHERE status IN ('running', 'ready', 'failed')
SQL);
            } else {
                $existing = $this->optionalRow($this->query(<<<'SQL'
SELECT id, shadow_table, phase
FROM address_search_rebuild_operations
WHERE status IN ('running', 'ready', 'failed')
ORDER BY id DESC
LIMIT 1
FOR UPDATE
SQL));
                if ($existing !== null) {
                    $operationId = self::integer($existing, 'id');
                    $ready = self::string($existing, 'phase') === 'ready';
                    $this->queryParams(
                        <<<'SQL'
UPDATE address_search_rebuild_operations
SET status = CASE WHEN phase = 'ready' THEN 'ready' ELSE 'running' END,
    batch_size = $2,
    attempt = attempt + 1,
    updated_at = now(),
    failed_at = NULL,
    error_message = NULL
WHERE id = $1
SQL,
                        [(string) $operationId, (string) $batchSize],
                    );
                    $this->query('COMMIT');

                    return new AddressSearchRebuildOperationState(
                        id: $operationId,
                        shadowTable: self::string($existing, 'shadow_table'),
                        resumed: true,
                        ready: $ready,
                    );
                }
            }

            $sequence = $this->row($this->query(<<<'SQL'
SELECT nextval(pg_get_serial_sequence('address_search_rebuild_operations', 'id')) AS id
SQL));
            $operationId = self::integer($sequence, 'id');
            $shadowTable = 'address_search_build_' . $operationId;
            $this->queryParams(
                <<<'SQL'
INSERT INTO address_search_rebuild_operations (
    id, status, phase, shadow_table, batch_size
) VALUES ($1, 'running', 'preparing', $2, $3)
SQL,
                [(string) $operationId, $shadowTable, (string) $batchSize],
            );
            $this->initializeTasks($operationId);
            $tasks = $this->scalarInteger($this->queryParams(
                'SELECT count(*) FROM address_search_rebuild_regions WHERE operation_id = $1',
                [(string) $operationId],
            ));
            if ($tasks === 0) {
                throw new AddressSearchRebuildFailed('No active regional GAR hierarchy rows were found for rebuild.');
            }
            $this->queryParams(
                'UPDATE address_search_rebuild_operations SET tasks_total = $2 WHERE id = $1',
                [(string) $operationId, (string) $tasks],
            );
            $this->query('COMMIT');

            return new AddressSearchRebuildOperationState(
                id: $operationId,
                shadowTable: $shadowTable,
                resumed: false,
                ready: false,
                abandonedTables: $abandonedTables,
            );
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function dropShadowTable(string $table): void
    {
        $this->query('DROP TABLE IF EXISTS ' . self::identifier($table) . ' CASCADE');
    }

    public function prepareShadow(int $operationId, string $table): void
    {
        $tableSql = self::identifier($table);
        $exists = $this->scalarString($this->queryParams('SELECT to_regclass($1)::text', [$table]));
        if ($exists === null) {
            $this->query(sprintf(
                <<<'SQL'
CREATE TABLE %s (
    id bigint GENERATED BY DEFAULT AS IDENTITY,
    region_code smallint NOT NULL,
    object_id bigint NOT NULL,
    object_guid uuid,
    address_string text NOT NULL,
    hierarchy_type varchar(3) NOT NULL,
    level integer NOT NULL,
    postal_code varchar(20),
    okato varchar(20),
    oktmo varchar(20),
    geo_lat double precision,
    geo_lon double precision,
    geocoded_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    search_vector tsvector GENERATED ALWAYS AS (
        to_tsvector('russian'::regconfig, coalesce(address_string, ''))
    ) STORED,
    geocode_source varchar(32),
    geocode_osm_type varchar(16),
    geocode_osm_id bigint,
    geocode_display_name text,
    address_source varchar(32) NOT NULL DEFAULT 'gar',
    address_source_id text,
    address_source_updated_at timestamp(0) without time zone,
    datamos_global_id bigint,
    datamos_unom bigint,
    datamos_nreg bigint,
    datamos_adm_area text,
    datamos_district text,
    datamos_kad_n jsonb NOT NULL DEFAULT '[]'::jsonb,
    datamos_kad_zu jsonb NOT NULL DEFAULT '[]'::jsonb,
    CONSTRAINT %s PRIMARY KEY (region_code, id),
    CONSTRAINT %s UNIQUE (region_code, object_id, hierarchy_type),
    CONSTRAINT %s CHECK (region_code BETWEEN 1 AND 99),
    CONSTRAINT %s CHECK (hierarchy_type IN ('adm', 'mun'))
) PARTITION BY LIST (region_code)
SQL,
                $tableSql,
                self::identifier('asr_' . $operationId . '_pk'),
                self::identifier('asr_' . $operationId . '_object'),
                self::identifier('asr_' . $operationId . '_region'),
                self::identifier('asr_' . $operationId . '_hierarchy'),
            ));
        }

        foreach ($this->regionCodes($operationId) as $regionCode) {
            $this->query(sprintf(
                'CREATE TABLE IF NOT EXISTS %s PARTITION OF %s FOR VALUES IN (%d)',
                self::identifier($table . '_r' . $regionCode),
                $tableSql,
                $regionCode,
            ));
        }

        $this->queryParams(
            "UPDATE address_search_rebuild_operations SET phase = 'building', updated_at = now() WHERE id = $1",
            [(string) $operationId],
        );
    }

    /** @return list<AddressSearchRebuildTask> */
    public function tasks(int $operationId): array
    {
        $result = $this->queryParams(
            <<<'SQL'
SELECT region_code, hierarchy_type, last_hierarchy_id, source_max_hierarchy_id, status
FROM address_search_rebuild_regions
WHERE operation_id = $1
ORDER BY region_code, hierarchy_type
SQL,
            [(string) $operationId],
        );
        $tasks = [];
        while (($row = $this->optionalRow($result)) !== null) {
            $tasks[] = new AddressSearchRebuildTask(
                regionCode: self::integer($row, 'region_code'),
                hierarchy: self::string($row, 'hierarchy_type'),
                lastHierarchyId: self::integer($row, 'last_hierarchy_id'),
                sourceMaxHierarchyId: self::integer($row, 'source_max_hierarchy_id'),
                completed: self::string($row, 'status') === 'completed',
            );
        }

        return $tasks;
    }

    public function startTask(int $operationId, AddressSearchRebuildTask $task): void
    {
        $this->queryParams(
            <<<'SQL'
UPDATE address_search_rebuild_regions
SET status = 'running', started_at = COALESCE(started_at, now()), updated_at = now(),
    failed_at = NULL, error_message = NULL
WHERE operation_id = $1 AND region_code = $2 AND hierarchy_type = $3 AND status <> 'completed'
SQL,
            [(string) $operationId, (string) $task->regionCode, $task->hierarchy],
        );
    }

    public function nextBatch(AddressSearchRebuildTask $task, int $lastId, int $batchSize): ?AddressSearchBatchBoundary
    {
        $source = match ($task->hierarchy) {
            'adm' => <<<'SQL'
SELECT h.id
FROM administrative_reestr_hierarchy h
WHERE h.is_active = true
  AND h.end_date > CURRENT_DATE
  AND h.region_code = $1
  AND h.id > $2
  AND h.id <= $3
ORDER BY h.id
LIMIT $4
SQL,
            'mun' => <<<'SQL'
SELECT h.id
FROM municipal_reestr_hierarchy h
WHERE h.is_active = true
  AND h.end_date > CURRENT_DATE
  AND h.id > $2
  AND h.id <= $3
  AND EXISTS (
      SELECT 1
      FROM administrative_reestr_hierarchy region_h
      WHERE region_h.object_id = h.object_id
        AND region_h.region_code = $1
        AND region_h.is_active = true
        AND region_h.end_date > CURRENT_DATE
  )
ORDER BY h.id
LIMIT $4
SQL,
            default => throw new AddressSearchRebuildFailed('Unknown address search hierarchy task.'),
        };
        $row = $this->row($this->queryParams(
            "SELECT max(id) AS max_id, count(*) AS selected_rows FROM ({$source}) batch",
            [
                (string) $task->regionCode,
                (string) $lastId,
                (string) $task->sourceMaxHierarchyId,
                (string) $batchSize,
            ],
        ));
        if (($row['max_id'] ?? null) === null) {
            return null;
        }

        return new AddressSearchBatchBoundary(
            maxHierarchyId: self::integer($row, 'max_id'),
            selectedRows: self::integer($row, 'selected_rows'),
        );
    }

    public function writeBatch(
        int $operationId,
        string $shadowTable,
        AddressSearchRebuildTask $task,
        int $lastId,
        AddressSearchBatchBoundary $batch,
    ): void {
        $this->query('BEGIN');

        try {
            $written = pg_affected_rows($this->queryParams(
                AddressSearchRebuildSql::batch($task->hierarchy, self::identifier($shadowTable)),
                [
                    (string) $task->regionCode,
                    $task->hierarchy,
                    (string) $lastId,
                    (string) $batch->maxHierarchyId,
                ],
            ));
            $this->queryParams(
                <<<'SQL'
UPDATE address_search_rebuild_regions
SET last_hierarchy_id = $4,
    scanned_rows = scanned_rows + $5,
    written_rows = written_rows + $6,
    updated_at = now()
WHERE operation_id = $1 AND region_code = $2 AND hierarchy_type = $3
SQL,
                [
                    (string) $operationId,
                    (string) $task->regionCode,
                    $task->hierarchy,
                    (string) $batch->maxHierarchyId,
                    (string) $batch->selectedRows,
                    (string) $written,
                ],
            );
            $this->queryParams(
                <<<'SQL'
UPDATE address_search_rebuild_operations
SET phase = 'building', rows_scanned = rows_scanned + $2,
    rows_written = rows_written + $3, updated_at = now()
WHERE id = $1
SQL,
                [(string) $operationId, (string) $batch->selectedRows, (string) $written],
            );
            $this->query('COMMIT');
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function completeTask(int $operationId, AddressSearchRebuildTask $task): void
    {
        $this->query('BEGIN');

        try {
            $updated = $this->queryParams(
                <<<'SQL'
UPDATE address_search_rebuild_regions
SET status = 'completed', completed_at = now(), updated_at = now(), failed_at = NULL, error_message = NULL
WHERE operation_id = $1 AND region_code = $2 AND hierarchy_type = $3 AND status <> 'completed'
SQL,
                [(string) $operationId, (string) $task->regionCode, $task->hierarchy],
            );
            if (pg_affected_rows($updated) === 1) {
                $this->queryParams(
                    'UPDATE address_search_rebuild_operations SET tasks_completed = tasks_completed + 1, updated_at = now() WHERE id = $1',
                    [(string) $operationId],
                );
            }
            $this->query('COMMIT');
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function createIndexes(int $operationId, string $table): void
    {
        $this->queryParams(
            "UPDATE address_search_rebuild_operations SET phase = 'indexing', updated_at = now() WHERE id = $1",
            [(string) $operationId],
        );
        $tableSql = self::identifier($table);
        $indexes = [
            'object_id' => '(object_id)',
            'object_guid' => '(object_guid)',
            'hierarchy' => '(hierarchy_type)',
            'level' => '(level)',
            'postal' => '(postal_code)',
            'okato' => '(okato)',
            'oktmo' => '(oktmo)',
            'updated' => '(updated_at)',
            'geocoded' => '(geocoded_at) WHERE geocoded_at IS NOT NULL',
            'geocode_source' => '(geocode_source) WHERE geocode_source IS NOT NULL',
            'geocode_osm' => '(geocode_osm_type, geocode_osm_id) WHERE geocode_osm_id IS NOT NULL',
            'source' => '(address_source)',
            'source_id' => '(address_source_id) WHERE address_source_id IS NOT NULL',
            'datamos_global' => '(datamos_global_id) WHERE datamos_global_id IS NOT NULL',
            'datamos_unom' => '(datamos_unom) WHERE datamos_unom IS NOT NULL',
            'datamos_nreg' => '(datamos_nreg) WHERE datamos_nreg IS NOT NULL',
            'region_hierarchy' => '(region_code, hierarchy_type)',
        ];
        foreach ($indexes as $suffix => $definition) {
            $this->query(sprintf(
                'CREATE INDEX IF NOT EXISTS %s ON %s %s',
                self::identifier('asr_' . $operationId . '_' . $suffix),
                $tableSql,
                $definition,
            ));
        }
        $this->query(sprintf(
            'CREATE INDEX IF NOT EXISTS %s ON %s USING GIN (search_vector)',
            self::identifier('asr_' . $operationId . '_search_vector'),
            $tableSql,
        ));
        $this->query(sprintf(
            'CREATE INDEX IF NOT EXISTS %s ON %s USING GIN (address_string gin_trgm_ops)',
            self::identifier('asr_' . $operationId . '_address_trgm'),
            $tableSql,
        ));
        $this->query('ANALYZE ' . $tableSql);
    }

    public function verify(int $operationId, string $table): AddressSearchRebuildParity
    {
        $this->queryParams(
            "UPDATE address_search_rebuild_operations SET phase = 'verifying', updated_at = now() WHERE id = $1",
            [(string) $operationId],
        );
        $tableSql = self::identifier($table);
        $shadowRows = $this->scalarInteger($this->query("SELECT count(*) FROM {$tableSql}"));
        $duplicateRows = $this->scalarInteger($this->query(<<<SQL
SELECT count(*)
FROM (
    SELECT region_code, object_id, hierarchy_type
    FROM {$tableSql}
    GROUP BY region_code, object_id, hierarchy_type
    HAVING count(*) > 1
) duplicates
SQL));
        $invalidRegionRows = $this->scalarInteger($this->query(
            "SELECT count(*) FROM {$tableSql} WHERE region_code NOT BETWEEN 1 AND 99",
        ));
        $missingDocuments = $this->scalarInteger($this->queryParams(<<<SQL
WITH source AS (
    SELECT task.region_code, task.hierarchy_type, h.object_id
    FROM address_search_rebuild_regions task
    JOIN administrative_reestr_hierarchy h
      ON task.hierarchy_type = 'adm'
     AND h.region_code = task.region_code
     AND h.is_active = true
     AND h.end_date > CURRENT_DATE
     AND h.id <= task.source_max_hierarchy_id
    WHERE task.operation_id = $1

    UNION ALL

    SELECT task.region_code, task.hierarchy_type, h.object_id
    FROM address_search_rebuild_regions task
    JOIN municipal_reestr_hierarchy h
      ON task.hierarchy_type = 'mun'
     AND h.is_active = true
     AND h.end_date > CURRENT_DATE
     AND h.id <= task.source_max_hierarchy_id
    WHERE task.operation_id = $1
      AND EXISTS (
          SELECT 1
          FROM administrative_reestr_hierarchy region_h
          WHERE region_h.object_id = h.object_id
            AND region_h.region_code = task.region_code
            AND region_h.is_active = true
            AND region_h.end_date > CURRENT_DATE
      )
)
SELECT count(*)
FROM source
WHERE NOT EXISTS (
    SELECT 1
    FROM {$tableSql} shadow
    WHERE shadow.region_code = source.region_code
      AND shadow.object_id = source.object_id
      AND shadow.hierarchy_type = source.hierarchy_type
)
SQL, [(string) $operationId]));
        $checkpointGap = $this->scalarInteger($this->queryParams(
            <<<'SQL'
SELECT coalesce(sum(greatest(source_rows - scanned_rows, 0)), 0)
FROM address_search_rebuild_regions
WHERE operation_id = $1
SQL,
            [(string) $operationId],
        ));
        $missingRows = $missingDocuments + $checkpointGap;

        $parity = new AddressSearchRebuildParity(
            shadowRows: $shadowRows,
            missingRows: $missingRows,
            duplicateRows: $duplicateRows,
            invalidRegionRows: $invalidRegionRows,
        );
        $this->queryParams(
            <<<'SQL'
UPDATE address_search_rebuild_operations
SET shadow_rows = $2, missing_rows = $3, duplicate_rows = $4,
    invalid_region_rows = $5, updated_at = now()
WHERE id = $1
SQL,
            [
                (string) $operationId,
                (string) $parity->shadowRows,
                (string) $parity->missingRows,
                (string) $parity->duplicateRows,
                (string) $parity->invalidRegionRows,
            ],
        );

        return $parity;
    }

    public function markReady(int $operationId, int $elapsedMilliseconds): void
    {
        $updated = $this->queryParams(
            <<<'SQL'
UPDATE address_search_rebuild_operations
SET status = 'ready', phase = 'ready', ready_at = now(), updated_at = now(),
    elapsed_milliseconds = elapsed_milliseconds + $2, failed_at = NULL, error_message = NULL
WHERE id = $1 AND tasks_completed = tasks_total
  AND shadow_rows > 0 AND missing_rows = 0 AND duplicate_rows = 0 AND invalid_region_rows = 0
SQL,
            [(string) $operationId, (string) $elapsedMilliseconds],
        );
        if (pg_affected_rows($updated) !== 1) {
            throw new AddressSearchRebuildFailed('address_search shadow cannot become ready before parity succeeds.');
        }
    }

    public function addElapsed(int $operationId, int $elapsedMilliseconds): void
    {
        $this->queryParams(
            'UPDATE address_search_rebuild_operations SET elapsed_milliseconds = elapsed_milliseconds + $2 WHERE id = $1',
            [(string) $operationId, (string) $elapsedMilliseconds],
        );
    }

    public function switch(int $operationId, string $shadowTable): void
    {
        $previousTable = 'address_search_previous_' . $operationId;
        $this->query('BEGIN');

        try {
            $this->queryParams(
                "UPDATE address_search_rebuild_operations SET status = 'running', phase = 'switching', updated_at = now() WHERE id = $1 AND phase = 'ready'",
                [(string) $operationId],
            );
            $this->query('LOCK TABLE address_search IN ACCESS EXCLUSIVE MODE');
            $this->query('DROP TABLE IF EXISTS ' . self::identifier($previousTable) . ' CASCADE');
            $this->query('ALTER TABLE address_search RENAME TO ' . self::identifier($previousTable));
            $this->query('ALTER TABLE ' . self::identifier($shadowTable) . ' RENAME TO address_search');
            $this->queryParams(
                <<<'SQL'
UPDATE address_search_rebuild_operations
SET status = 'completed', phase = 'completed', previous_table = $2,
    completed_at = now(), updated_at = now(), failed_at = NULL, error_message = NULL
WHERE id = $1
SQL,
                [(string) $operationId, $previousTable],
            );
            $this->query('COMMIT');
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }

        $this->dropShadowTable($previousTable);
    }

    public function failOperation(int $operationId, ?AddressSearchRebuildTask $task, Throwable $error): void
    {
        $message = substr($error->getMessage(), 0, 2000);

        try {
            if ($task !== null) {
                $this->queryParams(
                    <<<'SQL'
UPDATE address_search_rebuild_regions
SET status = 'failed', failed_at = now(), updated_at = now(), error_message = $4
WHERE operation_id = $1 AND region_code = $2 AND hierarchy_type = $3 AND status <> 'completed'
SQL,
                    [(string) $operationId, (string) $task->regionCode, $task->hierarchy, $message],
                );
            }
            $this->queryParams(
                <<<'SQL'
UPDATE address_search_rebuild_operations
SET status = 'failed', failed_at = now(), updated_at = now(), error_message = $2
WHERE id = $1 AND status <> 'completed'
SQL,
                [(string) $operationId, $message],
            );
        } catch (Throwable) {
            // Preserve the original rebuild failure when the connection is already lost.
        }
    }

    public function result(int $operationId, bool $resumed): RebuildAddressSearchResult
    {
        $row = $this->row($this->queryParams(
            <<<'SQL'
SELECT status, tasks_completed, tasks_total, rows_scanned, rows_written, shadow_rows, elapsed_milliseconds
FROM address_search_rebuild_operations
WHERE id = $1
SQL,
            [(string) $operationId],
        ));

        return new RebuildAddressSearchResult(
            operationId: $operationId,
            status: self::string($row, 'status'),
            tasksCompleted: self::integer($row, 'tasks_completed'),
            tasksTotal: self::integer($row, 'tasks_total'),
            rowsScanned: self::integer($row, 'rows_scanned'),
            rowsWritten: self::integer($row, 'rows_written'),
            shadowRows: self::integer($row, 'shadow_rows'),
            elapsedMilliseconds: self::integer($row, 'elapsed_milliseconds'),
            resumed: $resumed,
        );
    }

    private function initializeTasks(int $operationId): void
    {
        $this->queryParams(
            <<<'SQL'
INSERT INTO address_search_rebuild_regions (
    operation_id, region_code, hierarchy_type, source_max_hierarchy_id, source_rows
)
SELECT $1::bigint, h.region_code, 'adm', max(h.id), count(*)
FROM administrative_reestr_hierarchy h
WHERE h.is_active = true
  AND h.end_date > CURRENT_DATE
  AND h.region_code BETWEEN 1 AND 99
GROUP BY h.region_code

UNION ALL

SELECT $1::bigint, region.region_code, 'mun', max(h.id), count(*)
FROM municipal_reestr_hierarchy h
JOIN LATERAL (
    SELECT region_h.region_code
    FROM administrative_reestr_hierarchy region_h
    WHERE region_h.object_id = h.object_id
      AND region_h.is_active = true
      AND region_h.end_date > CURRENT_DATE
      AND region_h.region_code BETWEEN 1 AND 99
    ORDER BY region_h.id DESC
    LIMIT 1
) region ON true
WHERE h.is_active = true
  AND h.end_date > CURRENT_DATE
GROUP BY region.region_code
SQL,
            [(string) $operationId],
        );
    }

    /** @return list<int> */
    private function regionCodes(int $operationId): array
    {
        $result = $this->queryParams(
            'SELECT DISTINCT region_code FROM address_search_rebuild_regions WHERE operation_id = $1 ORDER BY region_code',
            [(string) $operationId],
        );
        $regions = [];
        while (($row = $this->optionalRow($result)) !== null) {
            $regions[] = self::integer($row, 'region_code');
        }

        return $regions;
    }

    private function query(string $sql): Result
    {
        $result = pg_query($this->connection, $sql);
        if (!$result instanceof Result) {
            throw new AddressSearchRebuildFailed('PostgreSQL query failed: ' . pg_last_error($this->connection));
        }

        return $result;
    }

    /** @param list<string> $parameters */
    private function queryParams(string $sql, array $parameters): Result
    {
        $result = pg_query_params($this->connection, $sql, $parameters);
        if (!$result instanceof Result) {
            throw new AddressSearchRebuildFailed('PostgreSQL query failed: ' . pg_last_error($this->connection));
        }

        return $result;
    }

    /** @return array<string, string|null> */
    private function row(Result $result): array
    {
        return $this->optionalRow($result)
            ?? throw new AddressSearchRebuildFailed('PostgreSQL rebuild state row was not found.');
    }

    /** @return array<string, string|null>|null */
    private function optionalRow(Result $result): ?array
    {
        $row = pg_fetch_assoc($result);
        if (!is_array($row)) {
            return null;
        }

        $normalized = [];
        foreach ($row as $key => $value) {
            if (!is_string($key)) {
                throw new AddressSearchRebuildFailed('PostgreSQL returned a non-string column name.');
            }
            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private function scalarInteger(Result $result): int
    {
        $row = pg_fetch_row($result);
        $value = is_array($row) ? ($row[0] ?? null) : null;
        if (!is_string($value) || !ctype_digit($value)) {
            throw new AddressSearchRebuildFailed('PostgreSQL returned an invalid integer scalar.');
        }

        return (int) $value;
    }

    private function scalarString(Result $result): ?string
    {
        $row = pg_fetch_row($result);
        $value = is_array($row) ? ($row[0] ?? null) : null;

        return is_string($value) ? $value : null;
    }

    /** @param array<array-key, string|null> $row */
    private static function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || !ctype_digit($value)) {
            throw new AddressSearchRebuildFailed("PostgreSQL returned an invalid integer field: {$key}");
        }

        return (int) $value;
    }

    /** @param array<array-key, string|null> $row */
    private static function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new AddressSearchRebuildFailed("PostgreSQL returned an invalid string field: {$key}");
        }

        return $value;
    }

    private static function identifier(string $value): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $value) !== 1) {
            throw new AddressSearchRebuildFailed('Unsafe PostgreSQL identifier in address search rebuild.');
        }

        return '"' . $value . '"';
    }

    private function rollback(): void
    {
        pg_query($this->connection, 'ROLLBACK');
    }
}
