<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use PgSql\Connection;
use PgSql\Result;
use Throwable;

final readonly class GarInitialLoadStore
{
    public function __construct(private Connection $connection) {}

    /** @param list<string> $tables */
    public function prepare(int $operationId, array $tables): void
    {
        if ($this->phase($operationId) !== 'prepare') {
            return;
        }

        $this->query('BEGIN');
        try {
            $qualifiedTables = \array_map(
                fn(string $table): string => 'public.' . $this->identifier($table),
                $tables,
            );
            $this->query('LOCK TABLE ' . \implode(', ', $qualifiedTables) . ' IN ACCESS EXCLUSIVE MODE');
            foreach ($qualifiedTables as $table) {
                $row = $this->row($this->query("SELECT EXISTS (SELECT 1 FROM {$table} LIMIT 1) AS has_rows"));
                if (($row['has_rows'] ?? null) === 't') {
                    throw new GarImportFailed(
                        'GAR initial-load requires every canonical GAR table to be empty; found data in ' . $table . '.',
                    );
                }
            }

            $operation = $this->row($this->queryParams(
                <<<'SQL'
SELECT defer_primary_keys, defer_foreign_keys
FROM gar_import_operations
WHERE id = $1 AND mode = 'initial-load' AND phase = 'prepare'
FOR UPDATE
SQL,
                [(string) $operationId],
            ));
            $deferPrimaryKeys = $this->boolean($operation, 'defer_primary_keys');
            $deferForeignKeys = $this->boolean($operation, 'defer_foreign_keys');

            $snapshotBacklog = $this->row($this->query(<<<'SQL'
SELECT
    (SELECT count(*) FROM gar_import_index_snapshots) AS indexes,
    (SELECT count(*) FROM gar_import_foreign_key_snapshots) AS foreign_keys
SQL));
            if (
                $this->integer($snapshotBacklog, 'indexes') !== 0
                || $this->integer($snapshotBacklog, 'foreign_keys') !== 0
            ) {
                throw new GarImportFailed(
                    'GAR initial-load found an unresolved index or foreign-key snapshot backlog.',
                );
            }

            $tableLiterals = \implode(', ', \array_map(
                fn(string $table): string => $this->literal($table),
                $tables,
            ));
            $indexes = $this->queryParams(<<<SQL
SELECT
    index_class.relname AS index_name,
    table_class.relname AS table_name,
    pg_get_indexdef(indexes.indexrelid) AS create_sql,
    CASE WHEN constraint_row.contype = 'p' THEN constraint_row.conname END AS constraint_name,
    CASE WHEN constraint_row.contype = 'p'
        THEN pg_get_constraintdef(constraint_row.oid, false)
    END AS constraint_definition
FROM pg_index indexes
JOIN pg_class index_class ON index_class.oid = indexes.indexrelid
JOIN pg_class table_class ON table_class.oid = indexes.indrelid
JOIN pg_namespace namespace ON namespace.oid = table_class.relnamespace
LEFT JOIN pg_constraint constraint_row ON constraint_row.conindid = indexes.indexrelid
WHERE namespace.nspname = 'public'
  AND table_class.relname IN ({$tableLiterals})
  AND index_class.relkind = 'i'
  AND indexes.indisvalid
  AND indexes.indisready
  AND (
      (
          $1::boolean
          AND indexes.indisprimary
          AND constraint_row.contype = 'p'
      )
      OR
      (
          NOT indexes.indisprimary
          AND NOT indexes.indisunique
          AND NOT indexes.indisexclusion
          AND constraint_row.oid IS NULL
      )
  )
ORDER BY table_class.relname, index_class.relname
SQL, [$deferPrimaryKeys ? 't' : 'f']);

            /** @var list<array{index: string, table: string, constraint: string|null}> $indexSnapshots */
            $indexSnapshots = [];
            while (($row = $this->optionalRow($indexes)) !== null) {
                $indexName = $this->string($row, 'index_name');
                $tableName = $this->string($row, 'table_name');
                $createSql = $this->string($row, 'create_sql');
                $constraintName = $this->nullableString($row, 'constraint_name');
                $constraintDefinition = $this->nullableString($row, 'constraint_definition');
                if (($constraintName === null) !== ($constraintDefinition === null)) {
                    throw new GarImportFailed("GAR primary-key snapshot is incomplete: {$indexName}");
                }
                $this->queryParams(
                    <<<'SQL'
INSERT INTO gar_import_index_snapshots (
    index_name, table_name, create_sql, operation_id, definition_hash,
    constraint_name, constraint_definition, constraint_definition_hash
) VALUES ($1, $2, $3, $4, $5, $6, $7, $8)
SQL,
                    [
                        $indexName,
                        $tableName,
                        $createSql,
                        (string) $operationId,
                        \hash('sha256', $createSql),
                        $constraintName,
                        $constraintDefinition,
                        $constraintDefinition === null ? null : \hash('sha256', $constraintDefinition),
                    ],
                );
                $indexSnapshots[] = [
                    'index' => $indexName,
                    'table' => $tableName,
                    'constraint' => $constraintName,
                ];
            }

            /** @var list<array{table: string, constraint: string}> $foreignKeys */
            $foreignKeys = [];
            if ($deferForeignKeys) {
                $constraints = $this->query(<<<SQL
SELECT
    table_class.relname AS table_name,
    constraint_row.conname AS constraint_name,
    pg_get_constraintdef(constraint_row.oid, false) AS definition_sql
FROM pg_constraint constraint_row
JOIN pg_class table_class ON table_class.oid = constraint_row.conrelid
JOIN pg_namespace namespace ON namespace.oid = table_class.relnamespace
WHERE namespace.nspname = 'public'
  AND table_class.relname IN ({$tableLiterals})
  AND constraint_row.contype = 'f'
ORDER BY table_class.relname, constraint_row.conname
SQL);
                while (($row = $this->optionalRow($constraints)) !== null) {
                    $tableName = $this->string($row, 'table_name');
                    $constraintName = $this->string($row, 'constraint_name');
                    $definitionSql = $this->string($row, 'definition_sql');
                    $this->queryParams(
                        <<<'SQL'
INSERT INTO gar_import_foreign_key_snapshots (
    operation_id, table_name, constraint_name, definition_sql, definition_hash
) VALUES ($1, $2, $3, $4, $5)
SQL,
                        [
                            (string) $operationId,
                            $tableName,
                            $constraintName,
                            $definitionSql,
                            \hash('sha256', $definitionSql),
                        ],
                    );
                    $foreignKeys[] = ['table' => $tableName, 'constraint' => $constraintName];
                }
            }

            foreach ($foreignKeys as $foreignKey) {
                $this->query(\sprintf(
                    'ALTER TABLE public.%s DROP CONSTRAINT %s',
                    $this->identifier($foreignKey['table']),
                    $this->identifier($foreignKey['constraint']),
                ));
            }
            foreach ($indexSnapshots as $snapshot) {
                if ($snapshot['constraint'] !== null) {
                    $this->query(\sprintf(
                        'ALTER TABLE public.%s DROP CONSTRAINT %s',
                        $this->identifier($snapshot['table']),
                        $this->identifier($snapshot['constraint']),
                    ));
                    continue;
                }
                $this->query('DROP INDEX public.' . $this->identifier($snapshot['index']));
            }
            $this->queryParams(
                <<<'SQL'
UPDATE gar_import_operations
SET phase = 'import', indexes_total = $2, indexes_completed = 0,
    foreign_keys_total = $3, foreign_keys_completed = 0, updated_at = now()
WHERE id = $1 AND mode = 'initial-load' AND phase = 'prepare'
SQL,
                [(string) $operationId, (string) \count($indexSnapshots), (string) \count($foreignKeys)],
            );
            $this->query('COMMIT');
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function beginIndexBuild(int $operationId): void
    {
        $this->query('BEGIN');
        try {
            $operation = $this->row($this->queryParams(
                <<<'SQL'
SELECT files_total, files_completed, phase
FROM gar_import_operations
WHERE id = $1 AND mode = 'initial-load'
FOR UPDATE
SQL,
                [(string) $operationId],
            ));
            if (
                $this->integer($operation, 'files_total') !== $this->integer($operation, 'files_completed')
                || $this->string($operation, 'phase') !== 'import'
            ) {
                throw new GarImportFailed('GAR index build cannot start before the initial-load import phase completes.');
            }
            $this->queryParams(
                <<<'SQL'
UPDATE gar_import_index_snapshots
SET status = 'pending', started_at = NULL, error_message = NULL
WHERE operation_id = $1 AND status IN ('building', 'failed')
SQL,
                [(string) $operationId],
            );
            $this->refreshCompletedCount($operationId);
            $this->queryParams(
                <<<'SQL'
UPDATE gar_import_operations
SET phase = 'index-build', index_build_started_at = COALESCE(index_build_started_at, now()),
    updated_at = now()
WHERE id = $1
SQL,
                [(string) $operationId],
            );
            $this->query('COMMIT');
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function resumeIndexBuild(int $operationId): void
    {
        $this->queryParams(
            <<<'SQL'
UPDATE gar_import_index_snapshots
SET status = 'pending', started_at = NULL, error_message = NULL
WHERE operation_id = $1 AND status IN ('building', 'failed')
SQL,
            [(string) $operationId],
        );
        $this->refreshCompletedCount($operationId);
    }

    /** @return list<GarIndexSnapshot> */
    public function pendingIndexes(int $operationId): array
    {
        $result = $this->queryParams(
            <<<'SQL'
SELECT snapshot.index_name, snapshot.table_name
FROM gar_import_index_snapshots snapshot
JOIN pg_class table_class ON table_class.relname = snapshot.table_name
JOIN pg_namespace namespace ON namespace.oid = table_class.relnamespace
WHERE snapshot.operation_id = $1
  AND snapshot.status <> 'completed'
  AND namespace.nspname = 'public'
ORDER BY pg_relation_size(table_class.oid) DESC,
         (snapshot.constraint_name IS NULL), snapshot.table_name, snapshot.index_name
SQL,
            [(string) $operationId],
        );
        $indexes = [];
        while (($row = $this->optionalRow($result)) !== null) {
            $indexes[] = new GarIndexSnapshot(
                $this->string($row, 'index_name'),
                $this->string($row, 'table_name'),
            );
        }

        return $indexes;
    }

    /** @return list<GarIndexSnapshot> */
    public function pendingPrimaryIndexes(int $operationId): array
    {
        $result = $this->queryParams(
            <<<'SQL'
SELECT snapshot.index_name, snapshot.table_name
FROM gar_import_index_snapshots snapshot
JOIN pg_class table_class ON table_class.relname = snapshot.table_name
JOIN pg_namespace namespace ON namespace.oid = table_class.relnamespace
WHERE snapshot.operation_id = $1
  AND snapshot.status <> 'completed'
  AND snapshot.constraint_name IS NOT NULL
  AND namespace.nspname = 'public'
ORDER BY pg_relation_size(table_class.oid) DESC, snapshot.table_name, snapshot.index_name
SQL,
            [(string) $operationId],
        );
        $indexes = [];
        while (($row = $this->optionalRow($result)) !== null) {
            $indexes[] = new GarIndexSnapshot(
                $this->string($row, 'index_name'),
                $this->string($row, 'table_name'),
            );
        }

        return $indexes;
    }

    /** @return list<GarIndexSnapshot> */
    public function pendingIndexesForCompletedTables(int $operationId): array
    {
        $result = $this->queryParams(
            <<<'SQL'
SELECT snapshot.index_name, snapshot.table_name
FROM gar_import_index_snapshots snapshot
JOIN gar_import_operations operation ON operation.id = snapshot.operation_id
JOIN pg_class table_class ON table_class.relname = snapshot.table_name
JOIN pg_namespace namespace ON namespace.oid = table_class.relnamespace
WHERE snapshot.operation_id = $1
  AND snapshot.status <> 'completed'
  AND operation.phase = 'import'
  AND namespace.nspname = 'public'
  AND NOT EXISTS (
      SELECT 1
      FROM gar_import_files file
      WHERE file.operation_id = snapshot.operation_id
        AND file.table_name = snapshot.table_name
        AND file.status <> 'completed'
  )
ORDER BY pg_relation_size(table_class.oid) DESC,
         (snapshot.constraint_name IS NULL), snapshot.table_name, snapshot.index_name
SQL,
            [(string) $operationId],
        );
        $indexes = [];
        while (($row = $this->optionalRow($result)) !== null) {
            $indexes[] = new GarIndexSnapshot(
                $this->string($row, 'index_name'),
                $this->string($row, 'table_name'),
            );
        }

        return $indexes;
    }

    public function completeIndexBuild(int $operationId): void
    {
        $row = $this->row($this->queryParams(
            <<<'SQL'
SELECT
    operation.indexes_total,
    count(snapshot.index_name) FILTER (WHERE snapshot.status = 'completed') AS completed,
    count(snapshot.index_name) FILTER (WHERE snapshot.status <> 'completed') AS incomplete
FROM gar_import_operations operation
LEFT JOIN gar_import_index_snapshots snapshot ON snapshot.operation_id = operation.id
WHERE operation.id = $1 AND operation.phase = 'index-build'
GROUP BY operation.id
SQL,
            [(string) $operationId],
        ));
        if (
            $this->integer($row, 'indexes_total') !== $this->integer($row, 'completed')
            || $this->integer($row, 'incomplete') !== 0
        ) {
            throw new GarImportFailed('GAR initial-load cannot continue while secondary indexes are incomplete.');
        }
        $this->queryParams(
            <<<'SQL'
UPDATE gar_import_operations
SET phase = CASE WHEN defer_foreign_keys THEN 'foreign-key-restore' ELSE 'analyze' END,
    indexes_completed = indexes_total,
    index_build_completed_at = COALESCE(
        (
            SELECT max(snapshot.completed_at)
            FROM gar_import_index_snapshots snapshot
            WHERE snapshot.operation_id = gar_import_operations.id
        ),
        now()
    ),
    foreign_key_restore_started_at = CASE
        WHEN defer_foreign_keys THEN COALESCE(foreign_key_restore_started_at, now())
        ELSE foreign_key_restore_started_at
    END,
    updated_at = now()
WHERE id = $1 AND phase = 'index-build'
SQL,
            [(string) $operationId],
        );
    }

    public function restoreForeignKeys(int $operationId): void
    {
        $this->restoreForeignKeysInPhase($operationId, 'foreign-key-restore', true);
    }

    public function restoreForeignKeysForIndexOverlap(int $operationId): void
    {
        $this->restoreForeignKeysInPhase($operationId, 'index-build', false);
    }

    private function restoreForeignKeysInPhase(
        int $operationId,
        string $expectedPhase,
        bool $advancePhase,
    ): void {
        $this->query('BEGIN');
        try {
            $operation = $this->row($this->queryParams(
                <<<'SQL'
SELECT foreign_keys_total, defer_foreign_keys, phase
FROM gar_import_operations
WHERE id = $1 AND mode = 'initial-load'
FOR UPDATE
SQL,
                [(string) $operationId],
            ));
            if (
                !$this->boolean($operation, 'defer_foreign_keys')
                || $this->string($operation, 'phase') !== $expectedPhase
            ) {
                throw new GarImportFailed('GAR foreign-key restore cannot run outside its initial-load phase.');
            }
            $this->queryParams(
                'UPDATE gar_import_operations '
                    . 'SET foreign_key_restore_started_at = COALESCE(foreign_key_restore_started_at, now()), '
                    . 'updated_at = now() WHERE id = $1 AND phase = $2',
                [(string) $operationId, $expectedPhase],
            );

            $snapshots = $this->queryParams(
                <<<'SQL'
SELECT table_name, constraint_name, definition_sql, definition_hash
FROM gar_import_foreign_key_snapshots
WHERE operation_id = $1
ORDER BY table_name, constraint_name
FOR UPDATE
SQL,
                [(string) $operationId],
            );
            $seen = 0;
            while (($snapshot = $this->optionalRow($snapshots)) !== null) {
                ++$seen;
                $tableName = $this->string($snapshot, 'table_name');
                $constraintName = $this->string($snapshot, 'constraint_name');
                $definitionSql = $this->string($snapshot, 'definition_sql');
                $expectedHash = $this->string($snapshot, 'definition_hash');
                if (!\hash_equals($expectedHash, \hash('sha256', $definitionSql))) {
                    throw new GarImportFailed("GAR foreign-key snapshot hash mismatch: {$constraintName}");
                }

                $existing = $this->foreignKeyDefinition($tableName, $constraintName);
                if ($existing === null) {
                    $this->query(\sprintf(
                        'ALTER TABLE public.%s ADD CONSTRAINT %s %s NOT VALID',
                        $this->identifier($tableName),
                        $this->identifier($constraintName),
                        $definitionSql,
                    ));
                    $existing = $this->foreignKeyDefinition($tableName, $constraintName);
                }
                if ($existing === null) {
                    throw new GarImportFailed("Restored GAR foreign key was not found: {$constraintName}");
                }
                if (!\hash_equals($expectedHash, \hash('sha256', $existing['definition']))) {
                    throw new GarImportFailed("Restored GAR foreign key does not match its snapshot: {$constraintName}");
                }
                $validated = $existing['validated'];
                $this->queryParams(
                    <<<'SQL'
UPDATE gar_import_foreign_key_snapshots
SET status = $4::varchar(16),
    completed_at = CASE WHEN $4::varchar(16) = 'completed' THEN now() ELSE NULL END,
    error_message = NULL
WHERE operation_id = $1 AND table_name = $2 AND constraint_name = $3
SQL,
                    [
                        (string) $operationId,
                        $tableName,
                        $constraintName,
                        $validated ? 'completed' : 'restored',
                    ],
                );
            }
            if ($seen !== $this->integer($operation, 'foreign_keys_total')) {
                throw new GarImportFailed('GAR foreign-key snapshot count does not match the operation state.');
            }
            $this->refreshForeignKeyCompletedCount($operationId);
            if ($advancePhase) {
                $this->queryParams(
                    <<<'SQL'
UPDATE gar_import_operations
SET phase = 'foreign-key-validate',
    foreign_key_restore_completed_at = COALESCE(foreign_key_restore_completed_at, now()),
    foreign_key_validation_started_at = COALESCE(foreign_key_validation_started_at, now()),
    updated_at = now()
WHERE id = $1 AND phase = 'foreign-key-restore'
SQL,
                    [(string) $operationId],
                );
            } else {
                $this->queryParams(
                    <<<'SQL'
UPDATE gar_import_operations
SET foreign_key_restore_completed_at = COALESCE(foreign_key_restore_completed_at, now()),
    foreign_key_validation_started_at = COALESCE(foreign_key_validation_started_at, now()),
    updated_at = now()
WHERE id = $1 AND phase = 'index-build'
SQL,
                    [(string) $operationId],
                );
            }
            $this->query('COMMIT');
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function resumeForeignKeyValidation(int $operationId): void
    {
        $this->queryParams(
            <<<'SQL'
UPDATE gar_import_foreign_key_snapshots
SET status = 'restored', started_at = NULL, error_message = NULL
WHERE operation_id = $1 AND status IN ('validating', 'failed')
SQL,
            [(string) $operationId],
        );
        $this->refreshForeignKeyCompletedCount($operationId);
    }

    /** @return list<GarForeignKeySnapshot> */
    public function pendingForeignKeys(int $operationId): array
    {
        $result = $this->queryParams(
            <<<'SQL'
SELECT snapshot.constraint_name, snapshot.table_name
FROM gar_import_foreign_key_snapshots snapshot
JOIN pg_class table_class ON table_class.relname = snapshot.table_name
JOIN pg_namespace namespace ON namespace.oid = table_class.relnamespace
    AND namespace.nspname = 'public'
WHERE snapshot.operation_id = $1 AND snapshot.status <> 'completed'
ORDER BY pg_relation_size(table_class.oid) DESC, snapshot.table_name, snapshot.constraint_name
SQL,
            [(string) $operationId],
        );
        $constraints = [];
        while (($row = $this->optionalRow($result)) !== null) {
            $constraints[] = new GarForeignKeySnapshot(
                $this->string($row, 'constraint_name'),
                $this->string($row, 'table_name'),
            );
        }

        return $constraints;
    }

    public function completeForeignKeyValidation(int $operationId): void
    {
        $row = $this->row($this->queryParams(
            <<<'SQL'
SELECT
    operation.foreign_keys_total,
    count(snapshot.constraint_name) FILTER (WHERE snapshot.status = 'completed') AS completed,
    count(snapshot.constraint_name) FILTER (WHERE snapshot.status <> 'completed') AS incomplete
FROM gar_import_operations operation
LEFT JOIN gar_import_foreign_key_snapshots snapshot ON snapshot.operation_id = operation.id
WHERE operation.id = $1 AND operation.phase = 'foreign-key-validate'
GROUP BY operation.id
SQL,
            [(string) $operationId],
        ));
        if (
            $this->integer($row, 'foreign_keys_total') !== $this->integer($row, 'completed')
            || $this->integer($row, 'incomplete') !== 0
        ) {
            throw new GarImportFailed('GAR initial-load cannot continue while foreign keys are incomplete.');
        }

        $snapshots = $this->queryParams(
            <<<'SQL'
SELECT table_name, constraint_name, definition_hash
FROM gar_import_foreign_key_snapshots
WHERE operation_id = $1
ORDER BY table_name, constraint_name
SQL,
            [(string) $operationId],
        );
        while (($snapshot = $this->optionalRow($snapshots)) !== null) {
            $tableName = $this->string($snapshot, 'table_name');
            $constraintName = $this->string($snapshot, 'constraint_name');
            $expectedHash = $this->string($snapshot, 'definition_hash');
            $existing = $this->foreignKeyDefinition($tableName, $constraintName);
            if (
                $existing === null
                || !$existing['validated']
                || !\hash_equals($expectedHash, \hash('sha256', $existing['definition']))
            ) {
                throw new GarImportFailed("Validated GAR foreign key does not match its snapshot: {$constraintName}");
            }
        }
        $this->queryParams(
            <<<'SQL'
UPDATE gar_import_operations
SET phase = 'analyze', foreign_keys_completed = foreign_keys_total,
    foreign_key_validation_completed_at = COALESCE(
        (
            SELECT max(snapshot.completed_at)
            FROM gar_import_foreign_key_snapshots snapshot
            WHERE snapshot.operation_id = gar_import_operations.id
        ),
        now()
    ),
    updated_at = now()
WHERE id = $1 AND phase = 'foreign-key-validate'
SQL,
            [(string) $operationId],
        );
    }

    public function validateForeignKey(int $operationId, string $tableName, string $constraintName): void
    {
        try {
            $snapshot = $this->startForeignKeyValidation($operationId, $tableName, $constraintName);
            if ($snapshot === null) {
                return;
            }
            $expectedHash = $this->string($snapshot, 'definition_hash');
            $existing = $this->foreignKeyDefinition($tableName, $constraintName);
            if (
                $existing === null
                || !\hash_equals($expectedHash, \hash('sha256', $existing['definition']))
            ) {
                throw new GarImportFailed("GAR foreign key does not match its snapshot: {$constraintName}");
            }
            if (!$existing['validated']) {
                $this->query(\sprintf(
                    'ALTER TABLE public.%s VALIDATE CONSTRAINT %s',
                    $this->identifier($tableName),
                    $this->identifier($constraintName),
                ));
            }
            $validated = $this->foreignKeyDefinition($tableName, $constraintName);
            if (
                $validated === null
                || !$validated['validated']
                || !\hash_equals($expectedHash, \hash('sha256', $validated['definition']))
            ) {
                throw new GarImportFailed("GAR foreign-key validation did not complete: {$constraintName}");
            }
            $this->finishForeignKeyValidation($operationId, $tableName, $constraintName);
        } catch (Throwable $error) {
            $this->failForeignKeyValidation($operationId, $tableName, $constraintName, $error);
            throw $error;
        }
    }

    /** @param list<string> $tables */
    public function analyze(int $operationId, array $tables): void
    {
        foreach ($tables as $table) {
            $this->query('ANALYZE public.' . $this->identifier($table));
        }
        $this->queryParams(
            <<<'SQL'
UPDATE gar_import_operations
SET phase = 'integrity', analyzed_at = now(), updated_at = now()
WHERE id = $1 AND phase = 'analyze'
SQL,
            [(string) $operationId],
        );
    }

    /** @param list<string> $tables */
    public function complete(int $operationId, array $tables): void
    {
        $tableLiterals = \implode(', ', \array_map(
            fn(string $table): string => $this->literal($table),
            $tables,
        ));
        $invalid = $this->integer($this->row($this->query(<<<SQL
SELECT count(*) AS value
FROM pg_index indexes
JOIN pg_class table_class ON table_class.oid = indexes.indrelid
JOIN pg_namespace namespace ON namespace.oid = table_class.relnamespace
WHERE namespace.nspname = 'public'
  AND table_class.relname IN ({$tableLiterals})
  AND (NOT indexes.indisvalid OR NOT indexes.indisready)
SQL)), 'value');
        if ($invalid !== 0) {
            throw new GarImportFailed('GAR initial-load found invalid or unfinished indexes after restoration.');
        }
        $unvalidatedForeignKeys = $this->integer($this->row($this->query(<<<SQL
SELECT count(*) AS value
FROM pg_constraint constraint_row
JOIN pg_class table_class ON table_class.oid = constraint_row.conrelid
JOIN pg_namespace namespace ON namespace.oid = table_class.relnamespace
WHERE namespace.nspname = 'public'
  AND table_class.relname IN ({$tableLiterals})
  AND constraint_row.contype = 'f'
  AND NOT constraint_row.convalidated
SQL)), 'value');
        if ($unvalidatedForeignKeys !== 0) {
            throw new GarImportFailed('GAR initial-load found unvalidated foreign keys after restoration.');
        }

        $this->query('BEGIN');
        try {
            $operation = $this->row($this->queryParams(
                <<<'SQL'
SELECT files_total, files_completed, rows_processed, rows_merged,
       indexes_total, indexes_completed, defer_foreign_keys,
       foreign_keys_total, foreign_keys_completed, phase
FROM gar_import_operations
WHERE id = $1 AND mode = 'initial-load'
FOR UPDATE
SQL,
                [(string) $operationId],
            ));
            if (
                $this->string($operation, 'phase') !== 'integrity'
                || $this->integer($operation, 'files_total') !== $this->integer($operation, 'files_completed')
                || $this->integer($operation, 'rows_processed') !== $this->integer($operation, 'rows_merged')
                || $this->integer($operation, 'indexes_total') !== $this->integer($operation, 'indexes_completed')
                || (
                    $this->boolean($operation, 'defer_foreign_keys')
                    && $this->integer($operation, 'foreign_keys_total')
                        !== $this->integer($operation, 'foreign_keys_completed')
                )
            ) {
                throw new GarImportFailed('GAR initial-load final integrity gate rejected incomplete operation state.');
            }
            $this->queryParams(
                'DELETE FROM gar_import_index_snapshots WHERE operation_id = $1',
                [(string) $operationId],
            );
            $this->queryParams(
                'DELETE FROM gar_import_foreign_key_snapshots WHERE operation_id = $1',
                [(string) $operationId],
            );
            $this->queryParams(
                <<<'SQL'
UPDATE gar_import_operations
SET status = 'completed', phase = 'ready', completed_at = now(), integrity_checked_at = now(),
    updated_at = now(), failed_at = NULL, error_message = NULL
WHERE id = $1
SQL,
                [(string) $operationId],
            );
            $this->query('COMMIT');
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function phase(int $operationId): string
    {
        return $this->string($this->row($this->queryParams(
            'SELECT phase FROM gar_import_operations WHERE id = $1 AND mode = \'initial-load\'',
            [(string) $operationId],
        )), 'phase');
    }

    public function rawImportCompleted(int $operationId): bool
    {
        $operation = $this->row($this->queryParams(
            <<<'SQL'
SELECT status, phase, files_total, files_completed
FROM gar_import_operations
WHERE id = $1 AND mode = 'initial-load'
SQL,
            [(string) $operationId],
        ));
        if (
            $this->string($operation, 'status') !== 'running'
            || $this->string($operation, 'phase') !== 'import'
        ) {
            throw new GarImportFailed('GAR index scheduler requires a running initial-load import phase.');
        }

        return $this->integer($operation, 'files_total') === $this->integer($operation, 'files_completed');
    }

    public function completedSourcePercentAtLeast(int $operationId, int $percent): bool
    {
        $progress = $this->row($this->queryParams(
            <<<'SQL'
SELECT COALESCE(sum(file_size) FILTER (WHERE status = 'completed'), 0)::numeric * 100
           >= COALESCE(sum(file_size), 0)::numeric * $2 AS admitted
FROM gar_import_files
WHERE operation_id = $1
SQL,
            [(string) $operationId, (string) $percent],
        ));

        return $this->boolean($progress, 'admitted');
    }

    public function buildIndex(int $operationId, string $indexName): void
    {
        try {
            $snapshot = $this->startIndex($operationId, $indexName);
            if ($snapshot === null) {
                return;
            }
            $createSql = $this->string($snapshot, 'create_sql');
            $expectedHash = $this->string($snapshot, 'definition_hash');
            $tableName = $this->string($snapshot, 'table_name');
            $constraintName = $this->nullableString($snapshot, 'constraint_name');
            $constraintDefinition = $this->nullableString($snapshot, 'constraint_definition');
            $constraintDefinitionHash = $this->nullableString($snapshot, 'constraint_definition_hash');
            if (
                ($constraintName === null) !== ($constraintDefinition === null)
                || ($constraintName === null) !== ($constraintDefinitionHash === null)
            ) {
                throw new GarImportFailed("GAR primary-key snapshot is incomplete: {$indexName}");
            }
            if (!\hash_equals($expectedHash, \hash('sha256', $createSql))) {
                throw new GarImportFailed("GAR index snapshot hash mismatch: {$indexName}");
            }

            $existing = $this->indexDefinition($indexName);
            if ($existing === null) {
                $this->query($createSql);
                $existing = $this->indexDefinition($indexName);
            }
            if ($existing === null) {
                throw new GarImportFailed("Restored GAR index was not found: {$indexName}");
            }
            if ($constraintName !== null && $constraintDefinition !== null && $constraintDefinitionHash !== null) {
                if (!\hash_equals($constraintDefinitionHash, \hash('sha256', $constraintDefinition))) {
                    throw new GarImportFailed("GAR primary-key snapshot hash mismatch: {$constraintName}");
                }
                $this->restorePrimaryKey(
                    $tableName,
                    $indexName,
                    $constraintName,
                    $constraintDefinitionHash,
                );
                $existing = $this->indexDefinition($indexName);
                if ($existing === null) {
                    throw new GarImportFailed("Restored GAR primary-key index was not found: {$indexName}");
                }
            }
            $restoredSql = $existing['create_sql'] ?? null;
            if (!\is_string($restoredSql)) {
                throw new GarImportFailed("Restored GAR index has no SQL definition: {$indexName}");
            }
            if (
                ($existing['indisvalid'] ?? null) !== 't'
                || ($existing['indisready'] ?? null) !== 't'
                || !\hash_equals($expectedHash, \hash('sha256', $restoredSql))
            ) {
                throw new GarImportFailed("Restored GAR index does not match its snapshot: {$indexName}");
            }
            $this->finishIndex($operationId, $indexName);
        } catch (Throwable $error) {
            $this->failIndex($operationId, $indexName, $error);
            throw $error;
        }
    }

    /** @return array<string, string|null>|null */
    private function startForeignKeyValidation(
        int $operationId,
        string $tableName,
        string $constraintName,
    ): ?array {
        $this->query('BEGIN');
        try {
            $row = $this->row($this->queryParams(
                <<<'SQL'
SELECT snapshot.definition_hash, snapshot.status
FROM gar_import_foreign_key_snapshots snapshot
JOIN gar_import_operations operation ON operation.id = snapshot.operation_id
WHERE snapshot.operation_id = $1
  AND snapshot.table_name = $2
  AND snapshot.constraint_name = $3
  AND operation.mode = 'initial-load'
  AND operation.defer_foreign_keys
  AND (
      operation.phase = 'foreign-key-validate'
      OR (
          operation.phase = 'index-build'
          AND operation.foreign_key_restore_completed_at IS NOT NULL
      )
  )
FOR UPDATE OF snapshot
SQL,
                [(string) $operationId, $tableName, $constraintName],
            ));
            $status = $this->string($row, 'status');
            if ($status === 'completed') {
                $this->query('COMMIT');

                return null;
            }
            if (!\in_array($status, ['restored', 'validating', 'failed'], true)) {
                throw new GarImportFailed("GAR foreign key is not ready for validation: {$constraintName}");
            }
            $this->queryParams(
                <<<'SQL'
UPDATE gar_import_foreign_key_snapshots
SET status = 'validating', attempt = attempt + 1, started_at = now(),
    completed_at = NULL, error_message = NULL
WHERE operation_id = $1 AND table_name = $2 AND constraint_name = $3
SQL,
                [(string) $operationId, $tableName, $constraintName],
            );
            $this->query('COMMIT');

            return $row;
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    private function finishForeignKeyValidation(
        int $operationId,
        string $tableName,
        string $constraintName,
    ): void {
        $this->query('BEGIN');
        try {
            $this->queryParams(
                <<<'SQL'
UPDATE gar_import_foreign_key_snapshots
SET status = 'completed', completed_at = now(), error_message = NULL
WHERE operation_id = $1 AND table_name = $2 AND constraint_name = $3
SQL,
                [(string) $operationId, $tableName, $constraintName],
            );
            $this->refreshForeignKeyCompletedCount($operationId);
            $this->query('COMMIT');
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    private function failForeignKeyValidation(
        int $operationId,
        string $tableName,
        string $constraintName,
        Throwable $error,
    ): void {
        try {
            $this->queryParams(
                <<<'SQL'
UPDATE gar_import_foreign_key_snapshots
SET status = 'failed', error_message = $4
WHERE operation_id = $1 AND table_name = $2 AND constraint_name = $3 AND status <> 'completed'
SQL,
                [
                    (string) $operationId,
                    $tableName,
                    $constraintName,
                    \substr($error->getMessage(), 0, 2000),
                ],
            );
        } catch (Throwable) {
            // Preserve the original validation failure when PostgreSQL is already unavailable.
        }
    }

    /** @return array{definition: string, validated: bool}|null */
    private function foreignKeyDefinition(string $tableName, string $constraintName): ?array
    {
        $row = $this->optionalRow($this->queryParams(
            <<<'SQL'
SELECT pg_get_constraintdef(constraint_row.oid, false) AS definition_sql,
       constraint_row.convalidated
FROM pg_constraint constraint_row
JOIN pg_class table_class ON table_class.oid = constraint_row.conrelid
JOIN pg_namespace namespace ON namespace.oid = table_class.relnamespace
WHERE namespace.nspname = 'public'
  AND table_class.relname = $1
  AND constraint_row.conname = $2
  AND constraint_row.contype = 'f'
SQL,
            [$tableName, $constraintName],
        ));
        if ($row === null) {
            return null;
        }
        $definition = $this->string($row, 'definition_sql');
        if (\str_ends_with($definition, ' NOT VALID')) {
            $definition = \substr($definition, 0, -10);
        }

        return [
            'definition' => $definition,
            'validated' => $this->boolean($row, 'convalidated'),
        ];
    }

    /** @return array<string, string|null>|null */
    private function startIndex(int $operationId, string $indexName): ?array
    {
        $this->query('BEGIN');
        try {
            $row = $this->row($this->queryParams(
                <<<'SQL'
SELECT snapshot.table_name, snapshot.create_sql, snapshot.definition_hash, snapshot.status,
       snapshot.constraint_name, snapshot.constraint_definition,
       snapshot.constraint_definition_hash
FROM gar_import_index_snapshots snapshot
JOIN gar_import_operations operation ON operation.id = snapshot.operation_id
WHERE snapshot.operation_id = $1 AND snapshot.index_name = $2
  AND operation.mode = 'initial-load'
  AND (
      operation.phase = 'index-build'
      OR (
          operation.phase = 'import'
          AND NOT EXISTS (
              SELECT 1
              FROM gar_import_files file
              WHERE file.operation_id = snapshot.operation_id
                AND file.table_name = snapshot.table_name
                AND file.status <> 'completed'
          )
      )
  )
FOR UPDATE OF snapshot
SQL,
                [(string) $operationId, $indexName],
            ));
            if ($this->string($row, 'status') === 'completed') {
                $this->query('COMMIT');

                return null;
            }
            $this->queryParams(
                <<<'SQL'
UPDATE gar_import_index_snapshots
SET status = 'building', attempt = attempt + 1, started_at = now(),
    completed_at = NULL, error_message = NULL
WHERE operation_id = $1 AND index_name = $2
SQL,
                [(string) $operationId, $indexName],
            );
            $this->queryParams(
                <<<'SQL'
UPDATE gar_import_operations
SET index_build_started_at = COALESCE(index_build_started_at, now()), updated_at = now()
WHERE id = $1
SQL,
                [(string) $operationId],
            );
            $this->query('COMMIT');

            return $row;
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    private function finishIndex(int $operationId, string $indexName): void
    {
        $this->query('BEGIN');
        try {
            $this->queryParams(
                <<<'SQL'
UPDATE gar_import_index_snapshots
SET status = 'completed', completed_at = now(), error_message = NULL
WHERE operation_id = $1 AND index_name = $2
SQL,
                [(string) $operationId, $indexName],
            );
            $this->refreshCompletedCount($operationId);
            $this->query('COMMIT');
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    private function failIndex(int $operationId, string $indexName, Throwable $error): void
    {
        try {
            $this->queryParams(
                <<<'SQL'
UPDATE gar_import_index_snapshots
SET status = 'failed', error_message = $3
WHERE operation_id = $1 AND index_name = $2 AND status <> 'completed'
SQL,
                [(string) $operationId, $indexName, \substr($error->getMessage(), 0, 2000)],
            );
        } catch (Throwable) {
            // Preserve the original index build failure when PostgreSQL is already unavailable.
        }
    }

    /** @return array<string, string|null>|null */
    private function indexDefinition(string $indexName): ?array
    {
        return $this->optionalRow($this->queryParams(
            <<<'SQL'
SELECT pg_get_indexdef(indexes.indexrelid) AS create_sql, indexes.indisvalid, indexes.indisready
FROM pg_index indexes
JOIN pg_class index_class ON index_class.oid = indexes.indexrelid
JOIN pg_namespace namespace ON namespace.oid = index_class.relnamespace
WHERE namespace.nspname = 'public' AND index_class.relname = $1
SQL,
            [$indexName],
        ));
    }

    private function restorePrimaryKey(
        string $tableName,
        string $indexName,
        string $constraintName,
        string $expectedDefinitionHash,
    ): void {
        $constraint = $this->primaryKeyDefinition($tableName, $constraintName);
        if ($constraint === null) {
            $this->query(\sprintf(
                'ALTER TABLE public.%s ADD CONSTRAINT %s PRIMARY KEY USING INDEX %s',
                $this->identifier($tableName),
                $this->identifier($constraintName),
                $this->identifier($indexName),
            ));
            $constraint = $this->primaryKeyDefinition($tableName, $constraintName);
        }
        if ($constraint === null) {
            throw new GarImportFailed("Restored GAR primary key was not found: {$constraintName}");
        }
        $definition = $constraint['definition_sql'] ?? null;
        if (
            !\is_string($definition)
            || ($constraint['validated'] ?? null) !== 't'
            || ($constraint['index_name'] ?? null) !== $indexName
            || !\hash_equals(
                $expectedDefinitionHash,
                \hash('sha256', $definition),
            )
        ) {
            throw new GarImportFailed("Restored GAR primary key does not match its snapshot: {$constraintName}");
        }
    }

    /** @return array<string, string|null>|null */
    private function primaryKeyDefinition(string $tableName, string $constraintName): ?array
    {
        return $this->optionalRow($this->queryParams(
            <<<'SQL'
SELECT pg_get_constraintdef(constraint_row.oid, false) AS definition_sql,
       constraint_row.convalidated AS validated,
       index_class.relname AS index_name
FROM pg_constraint constraint_row
JOIN pg_class table_class ON table_class.oid = constraint_row.conrelid
JOIN pg_namespace namespace ON namespace.oid = table_class.relnamespace
JOIN pg_class index_class ON index_class.oid = constraint_row.conindid
WHERE namespace.nspname = 'public'
  AND table_class.relname = $1
  AND constraint_row.conname = $2
  AND constraint_row.contype = 'p'
SQL,
            [$tableName, $constraintName],
        ));
    }

    private function refreshCompletedCount(int $operationId): void
    {
        $this->queryParams(
            <<<'SQL'
UPDATE gar_import_operations operation
SET indexes_completed = (
    SELECT count(*)
    FROM gar_import_index_snapshots snapshot
    WHERE snapshot.operation_id = operation.id AND snapshot.status = 'completed'
), updated_at = now()
WHERE operation.id = $1
SQL,
            [(string) $operationId],
        );
    }

    private function refreshForeignKeyCompletedCount(int $operationId): void
    {
        $this->queryParams(
            <<<'SQL'
UPDATE gar_import_operations operation
SET foreign_keys_completed = (
    SELECT count(*)
    FROM gar_import_foreign_key_snapshots snapshot
    WHERE snapshot.operation_id = operation.id AND snapshot.status = 'completed'
), updated_at = now()
WHERE operation.id = $1
SQL,
            [(string) $operationId],
        );
    }

    private function query(string $sql): Result
    {
        $result = \pg_query($this->connection, $sql);
        if (!$result instanceof Result) {
            throw new GarImportFailed('PostgreSQL query failed: ' . \pg_last_error($this->connection));
        }

        return $result;
    }

    /** @param list<string|null> $parameters */
    private function queryParams(string $sql, array $parameters): Result
    {
        $result = \pg_query_params($this->connection, $sql, $parameters);
        if (!$result instanceof Result) {
            throw new GarImportFailed('PostgreSQL query failed: ' . \pg_last_error($this->connection));
        }

        return $result;
    }

    /** @return array<string, string|null> */
    private function row(Result $result): array
    {
        return $this->optionalRow($result)
            ?? throw new GarImportFailed('PostgreSQL initial-load state row was not found.');
    }

    /** @return array<string, string|null>|null */
    private function optionalRow(Result $result): ?array
    {
        $row = \pg_fetch_assoc($result);
        if (!\is_array($row)) {
            return null;
        }

        $normalized = [];
        foreach ($row as $key => $value) {
            if (!\is_string($key)) {
                throw new GarImportFailed('PostgreSQL returned a non-string column name.');
            }
            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /** @param array<string, string|null> $row */
    private function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (!\is_string($value) || !\ctype_digit($value)) {
            throw new GarImportFailed("PostgreSQL returned an invalid integer field: {$key}");
        }

        return (int) $value;
    }

    /** @param array<string, string|null> $row */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!\is_string($value)) {
            throw new GarImportFailed("PostgreSQL returned an invalid string field: {$key}");
        }

        return $value;
    }

    /** @param array<string, string|null> $row */
    private function nullableString(array $row, string $key): ?string
    {
        return $row[$key] ?? null;
    }

    /** @param array<string, string|null> $row */
    private function boolean(array $row, string $key): bool
    {
        return match ($row[$key] ?? null) {
            't' => true,
            'f' => false,
            default => throw new GarImportFailed("PostgreSQL returned an invalid boolean field: {$key}"),
        };
    }

    private function identifier(string $value): string
    {
        $escaped = \pg_escape_identifier($this->connection, $value);
        if (!\is_string($escaped)) {
            throw new GarImportFailed('Unable to quote a PostgreSQL identifier.');
        }

        return $escaped;
    }

    private function literal(string $value): string
    {
        $escaped = \pg_escape_literal($this->connection, $value);
        if (!\is_string($escaped)) {
            throw new GarImportFailed('Unable to quote a PostgreSQL literal.');
        }

        return $escaped;
    }

    private function rollback(): void
    {
        \pg_query($this->connection, 'ROLLBACK');
    }
}
