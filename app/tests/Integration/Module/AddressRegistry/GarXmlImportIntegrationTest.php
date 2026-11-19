<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\AddressRegistry;

use Amp\Parallel\Context\ProcessContextFactory;
use Amp\Parallel\Worker\ContextWorkerFactory;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\TimeoutCancellation;
use App\Application\DependencyInjection\CliRuntimeModule;
use App\Application\Runtime\CliRuntime;
use App\Config\EnvironmentSnapshot;
use App\Infrastructure\Postgres\PostgresPoolFactory;
use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarDirectCopyBatchResult;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarDirectCopyBatchTask;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarDirectCopyStreamWriter;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarImportConnectionFactory;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarInitialLoadStore;
use App\Module\AddressRegistry\Infrastructure\GarImport\PostgresGarImportStore;
use App\Tests\Support\Database\IntegrationDatabase;
use PgSql\Connection;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;
use Thesis\Dic;
use Throwable;

#[Test]
final class GarXmlImportIntegrationTest
{
    public function testItStopsTheWorkOrchestratedInitialLoadAtTheRawBoundary(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 1);
        $representative = \dirname(__DIR__, 3) . '/Fixture/Gar/representative';

        try {
            IntegrationDatabase::resetSchema($pool);
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', ['--force-yes' => true]);

            [$exitCode, $output] = self::runRawImport(
                $dsn,
                $representative,
                'AS_REESTR_OBJECTS_20261104.XML',
                primaryWorkers: 1,
                childWorkersPerPrimary: 8,
            );
            Assert::same($exitCode, 0, $output);
            Assert::true(\str_contains($output, 'status=raw-completed'));
            Assert::true(\str_contains($output, 'boundary=raw'));
            Assert::true(\str_contains($output, 'durable_phase=import'));
            Assert::true(\str_contains($output, 'files=9/9'));
            Assert::true(\str_contains($output, 'rows=9'));
            Assert::true(\str_contains($output, 'copied=9'));
            Assert::true(\str_contains($output, 'range_tasks=1'));
            Assert::true(\str_contains($output, 'process_budget=9'));
            Assert::true(\str_contains($output, 'foreign_keys=deferred'));

            [$resumeExitCode, $resumeOutput] = self::runRawImport(
                $dsn,
                $representative,
                'AS_REESTR_OBJECTS_20261104.XML',
                primaryWorkers: 1,
                childWorkersPerPrimary: 8,
            );
            Assert::same($resumeExitCode, 0, $resumeOutput);
            Assert::true(\str_contains($resumeOutput, 'status=raw-completed'));
            Assert::true(\str_contains($resumeOutput, 'files=9/9'));
            Assert::true(\str_contains($resumeOutput, 'rows=9'));
            Assert::true(\str_contains($resumeOutput, 'range_tasks=0'));
            Assert::true(\str_contains($resumeOutput, 'resumed=yes'));

            $state = $pool->query(<<<'SQL'
SELECT
    operation.status,
    operation.attempt,
    operation.phase,
    operation.mode,
    operation.write_mode,
    operation.rows_processed,
    operation.rows_merged,
    operation.pipeline_writers,
    operation.pipeline_batches,
    operation.pipeline_bytes,
    operation.indexes_total,
    operation.indexes_completed,
    operation.foreign_keys_total,
    operation.foreign_keys_completed,
    (SELECT count(*) FROM gar_import_files WHERE status = 'completed') AS completed_files,
    (SELECT count(*) FROM address_objects WHERE reestr_object_id = 770001) AS address_objects,
    (SELECT count(*) FROM gar_import_file_batches) AS pending_ranges
FROM gar_import_operations operation
ORDER BY operation.id DESC
LIMIT 1
SQL)->fetchRow();
            Assert::notNull($state);
            Assert::same($state['status'] ?? null, 'running');
            Assert::same((int) ($state['attempt'] ?? -1), 2);
            Assert::same($state['phase'] ?? null, 'import');
            Assert::same($state['mode'] ?? null, 'initial-load');
            Assert::same($state['write_mode'] ?? null, 'direct-copy');
            Assert::same((int) ($state['rows_processed'] ?? -1), 9);
            Assert::same((int) ($state['rows_merged'] ?? -1), 9);
            Assert::same((int) ($state['pipeline_writers'] ?? -1), 8);
            Assert::true((int) ($state['pipeline_batches'] ?? 0) > 0);
            Assert::true((int) ($state['pipeline_bytes'] ?? 0) > 0);
            Assert::true((int) ($state['indexes_total'] ?? 0) > 0);
            Assert::same((int) ($state['indexes_completed'] ?? -1), 0);
            Assert::true((int) ($state['foreign_keys_total'] ?? 0) > 0);
            Assert::same((int) ($state['foreign_keys_completed'] ?? -1), 0);
            Assert::same((int) ($state['completed_files'] ?? -1), 9);
            Assert::same((int) ($state['address_objects'] ?? -1), 1);
            Assert::same((int) ($state['pending_ranges'] ?? -1), 0);
        } finally {
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }

    public function testItFinalizesTheWorkOrchestratedRawOperationThroughProductionImport(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 1);
        $representative = \dirname(__DIR__, 3) . '/Fixture/Gar/representative';

        try {
            IntegrationDatabase::resetSchema($pool);
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', ['--force-yes' => true]);

            [$rawExitCode, $rawOutput] = self::runRawImport(
                $dsn,
                $representative,
                'auto',
                primaryWorkers: 1,
                childWorkersPerPrimary: 8,
                deferPrimaryKeys: true,
            );
            Assert::same($rawExitCode, 0, $rawOutput);
            Assert::true(\str_contains($rawOutput, 'status=raw-completed'));
            Assert::true(\str_contains($rawOutput, 'files=9/9'));
            Assert::true(\str_contains($rawOutput, 'rows=9'));
            Assert::true(\str_contains($rawOutput, 'primary_keys=deferred'));
            Assert::true(\str_contains($rawOutput, 'foreign_keys=deferred'));

            [$finalizeExitCode, $finalizeOutput] = self::runImport(
                $dsn,
                $representative,
                restart: false,
                initialLoad: true,
                directCopy: true,
                pipelineFile: 'auto',
                pipelineWriters: 4,
                deferForeignKeys: true,
                pipelineParsers: 2,
                workers: 6,
                deferPrimaryKeys: true,
            );
            Assert::same($finalizeExitCode, 0, $finalizeOutput);
            Assert::true(\str_contains($finalizeOutput, 'status=completed'));
            Assert::true(\str_contains($finalizeOutput, 'phase=ready'));
            Assert::true(\str_contains($finalizeOutput, 'files=9/9'));
            Assert::true(\str_contains($finalizeOutput, 'rows=9'));
            Assert::true(\str_contains($finalizeOutput, 'resumed=yes'));

            $state = $pool->query(<<<'SQL'
SELECT
    operation.status,
    operation.attempt,
    operation.phase,
    operation.files_total,
    operation.files_completed,
    operation.rows_processed,
    operation.rows_merged,
    operation.indexes_total,
    operation.indexes_completed,
    operation.foreign_keys_total,
    operation.foreign_keys_completed,
    (SELECT count(*) FROM gar_import_file_batches) AS pending_ranges,
    (SELECT count(*) FROM gar_import_index_snapshots WHERE status <> 'completed') AS pending_indexes,
    (SELECT count(*) FROM gar_import_foreign_key_snapshots WHERE status <> 'completed') AS pending_foreign_keys
FROM gar_import_operations operation
ORDER BY operation.id DESC
LIMIT 1
SQL)->fetchRow();
            Assert::notNull($state);
            Assert::same($state['status'] ?? null, 'completed');
            Assert::same((int) ($state['attempt'] ?? -1), 2);
            Assert::same($state['phase'] ?? null, 'ready');
            Assert::same((int) ($state['files_total'] ?? -1), 9);
            Assert::same((int) ($state['files_completed'] ?? -1), 9);
            Assert::same((int) ($state['rows_processed'] ?? -1), 9);
            Assert::same((int) ($state['rows_merged'] ?? -1), 9);
            Assert::true((int) ($state['indexes_total'] ?? 0) > 0);
            Assert::same(
                (int) ($state['indexes_completed'] ?? -1),
                (int) ($state['indexes_total'] ?? -2),
            );
            Assert::true((int) ($state['foreign_keys_total'] ?? 0) > 0);
            Assert::same(
                (int) ($state['foreign_keys_completed'] ?? -1),
                (int) ($state['foreign_keys_total'] ?? -2),
            );
            Assert::same((int) ($state['pending_ranges'] ?? -1), 0);
            Assert::same((int) ($state['pending_indexes'] ?? -1), 0);
            Assert::same((int) ($state['pending_foreign_keys'] ?? -1), 0);
        } finally {
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }

    public function testItReportsStructuredWorkEvidenceForMalformedRawXml(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 1);
        $partial = \dirname(__DIR__, 3) . '/Fixture/Gar/partial';

        try {
            IntegrationDatabase::resetSchema($pool);
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', ['--force-yes' => true]);

            [$exitCode, $output] = self::runRawImport(
                $dsn,
                $partial,
                'AS_OPERATION_TYPES_20261104.XML',
            );
            Assert::same($exitCode, 1);
            Assert::true(\str_contains($output, 'GAR raw work-orchestrator run failed'));
            Assert::true(\str_contains($output, 'diagnostic[0]={'));
            Assert::true(\str_contains($output, '"kind":"application"'));
            Assert::true(\str_contains(
                $output,
                '"work_id":"' . $partial . '/AS_OPERATION_TYPES_20261104.XML"',
            ));
            Assert::false(\str_contains($output, 'Частичная операция'));

            $state = $pool->query(<<<'SQL'
SELECT
    operation.status AS operation_status,
    file.status AS file_status
FROM gar_import_operations operation
JOIN gar_import_files file ON file.operation_id = operation.id
ORDER BY operation.id DESC
LIMIT 1
SQL)->fetchRow();
            Assert::notNull($state);
            Assert::same($state['operation_status'] ?? null, 'failed');
            Assert::same($state['file_status'] ?? null, 'failed');
        } finally {
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }

    public function testItRejectsAWorkerWriteModeThatDoesNotMatchTheOperation(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 1);
        $connection = null;

        try {
            IntegrationDatabase::resetSchema($pool);
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', ['--force-yes' => true]);
            $operation = $pool->query(<<<'SQL'
INSERT INTO gar_import_operations (
    source_key, source_path, manifest_hash, status, batch_size, mode, phase, write_mode
) VALUES (
    repeat('a', 64), '/data/gar', repeat('b', 64), 'running', 50000,
    'initial-load', 'import', 'merge'
)
RETURNING id
SQL)->fetchRow();
            Assert::notNull($operation);
            $connection = (new GarImportConnectionFactory($dsn))->connect();
            $store = new PostgresGarImportStore($connection);

            Expect::exception(GarImportFailed::class);
            $store->assertWorkerWriteMode((int) ($operation['id'] ?? 0), true);
        } finally {
            if ($connection instanceof Connection) {
                \pg_close($connection);
            }
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }

    public function testItImportsResumesAndRestartsThroughAtomicCopyCheckpoints(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 1);
        $representative = \dirname(__DIR__, 3) . '/Fixture/Gar/representative';
        $partial = \dirname(__DIR__, 3) . '/Fixture/Gar/partial';

        try {
            IntegrationDatabase::resetSchema($pool);
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', ['--force-yes' => true]);

            [$exitCode, $output] = self::runImport($dsn, $representative, false);
            Assert::same($exitCode, 0);
            Assert::true(\str_contains($output, 'status=completed'));
            Assert::true(\str_contains($output, 'files=9/9'));
            Assert::true(\str_contains($output, 'rows=9'));
            Assert::true(\str_contains($output, 'workers=2'));

            $imported = $pool->query(<<<'SQL'
SELECT
    (SELECT count(*) FROM address_objects WHERE reestr_object_id = 770001) AS address_objects,
    (SELECT addr_type_id FROM address_objects WHERE reestr_object_id = 770001) AS addr_type_id,
    (SELECT orgname FROM normative_docs WHERE id = 3851715) AS normative_doc_orgname,
    (SELECT path_arr::text FROM administrative_reestr_hierarchy WHERE object_id = 770001) AS path_arr,
    (SELECT status FROM gar_import_operations ORDER BY id DESC LIMIT 1) AS operation_status,
    (SELECT count(*) FROM gar_import_files WHERE status = 'completed') AS completed_files
SQL)->fetchRow();
            Assert::notNull($imported);
            Assert::same((int) ($imported['address_objects'] ?? -1), 1);
            Assert::same((int) ($imported['addr_type_id'] ?? -1), 1);
            Assert::same($imported['normative_doc_orgname'] ?? null, '');
            Assert::same($imported['path_arr'] ?? null, '{770001}');
            Assert::same($imported['operation_status'] ?? null, 'completed');
            Assert::same((int) ($imported['completed_files'] ?? -1), 9);

            [$exitCode, $output] = self::runImport($dsn, $representative, false);
            Assert::same($exitCode, 0);
            Assert::true(\str_contains($output, 'status=already-completed'));

            [$exitCode] = self::runImport($dsn, $partial, false);
            Assert::same($exitCode, 1);
            $failed = $pool->query(<<<'SQL'
SELECT id, status, attempt, rows_processed
FROM gar_import_operations
WHERE source_path LIKE '%/partial'
ORDER BY id DESC
LIMIT 1
SQL)->fetchRow();
            Assert::notNull($failed);
            Assert::same($failed['status'] ?? null, 'failed');
            Assert::same((int) ($failed['attempt'] ?? -1), 1);
            Assert::same((int) ($failed['rows_processed'] ?? -1), 1);

            [$exitCode] = self::runImport($dsn, $partial, false);
            Assert::same($exitCode, 1);
            $resumed = $pool->query(<<<'SQL'
SELECT id, status, attempt, rows_processed
FROM gar_import_operations
WHERE source_path LIKE '%/partial'
ORDER BY id DESC
LIMIT 1
SQL)->fetchRow();
            Assert::notNull($resumed);
            Assert::same($resumed['id'] ?? null, $failed['id'] ?? null);
            Assert::same((int) ($resumed['attempt'] ?? -1), 2);
            Assert::same((int) ($resumed['rows_processed'] ?? -1), 1);

            [$exitCode] = self::runImport($dsn, $partial, true);
            Assert::same($exitCode, 1);
            $restart = $pool->query(<<<'SQL'
SELECT
    count(*) AS operations,
    count(*) FILTER (WHERE status = 'abandoned') AS abandoned,
    count(*) FILTER (WHERE status = 'failed') AS failed,
    max(rows_processed) AS max_rows,
    (SELECT count(*) FROM pg_class WHERE relname = 'gar_import_stage' AND relpersistence = 't') AS staging_tables
FROM gar_import_operations
WHERE source_path LIKE '%/partial'
SQL)->fetchRow();
            Assert::notNull($restart);
            Assert::same((int) ($restart['operations'] ?? -1), 2);
            Assert::same((int) ($restart['abandoned'] ?? -1), 1);
            Assert::same((int) ($restart['failed'] ?? -1), 1);
            Assert::same((int) ($restart['max_rows'] ?? -1), 1);
            Assert::same((int) ($restart['staging_tables'] ?? -1), 0);
        } finally {
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }

    public function testItRestoresSecondaryIndexesAfterAnInitialLoad(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 1);
        $representative = \dirname(__DIR__, 3) . '/Fixture/Gar/representative';
        $partial = \dirname(__DIR__, 3) . '/Fixture/Gar/partial';

        try {
            IntegrationDatabase::resetSchema($pool);
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', ['--force-yes' => true]);
            $before = self::secondaryIndexCount($pool);

            [$exitCode, $output] = self::runImport(
                $dsn,
                $representative,
                false,
                true,
                true,
                persistentIndexWorkers: true,
            );
            Assert::same($exitCode, 0);
            Assert::true(\str_contains($output, 'mode=initial-load'));
            Assert::true(\str_contains($output, 'write=direct-copy'));
            Assert::true(\str_contains($output, 'phase=ready'));
            Assert::true(\str_contains($output, 'persistent_index_workers=yes'));

            $operation = $pool->query(<<<'SQL'
SELECT
    status,
    mode,
    write_mode,
    phase,
    indexes_total,
    indexes_completed,
    analyzed_at IS NOT NULL AS analyzed,
    integrity_checked_at IS NOT NULL AS integrity_checked,
    (SELECT count(*) FROM gar_import_index_snapshots) AS snapshot_backlog,
    (
        SELECT count(*)
        FROM pg_index indexes
        WHERE NOT indexes.indisvalid OR NOT indexes.indisready
    ) AS invalid_indexes
FROM gar_import_operations
ORDER BY id DESC
LIMIT 1
SQL)->fetchRow();
            Assert::notNull($operation);
            Assert::same($operation['status'] ?? null, 'completed');
            Assert::same($operation['mode'] ?? null, 'initial-load');
            Assert::same($operation['write_mode'] ?? null, 'direct-copy');
            Assert::same($operation['phase'] ?? null, 'ready');
            Assert::same((int) ($operation['indexes_total'] ?? -1), $before);
            Assert::same((int) ($operation['indexes_completed'] ?? -1), $before);
            Assert::same($operation['analyzed'] ?? null, true);
            Assert::same($operation['integrity_checked'] ?? null, true);
            Assert::same((int) ($operation['snapshot_backlog'] ?? -1), 0);
            Assert::same((int) ($operation['invalid_indexes'] ?? -1), 0);
            Assert::same(self::secondaryIndexCount($pool), $before);

            [$exitCode, $output] = self::runImport($dsn, $partial, false, true, true);
            Assert::same($exitCode, 1);
            Assert::true(\str_contains($output, 'requires every canonical GAR table to be empty'));
            Assert::same(self::secondaryIndexCount($pool), $before);

            $pool->query(<<<'SQL'
INSERT INTO gar_import_index_snapshots (index_name, table_name, create_sql)
VALUES ('legacy_unresolved_index', 'address_objects', 'CREATE INDEX legacy_unresolved_index ON address_objects (id)')
SQL);
            [$exitCode, $output] = self::runImport($dsn, $representative, false);
            Assert::same($exitCode, 1);
            Assert::true(\str_contains($output, 'index or foreign-key recovery is incomplete'));
            $pool->query("DELETE FROM gar_import_index_snapshots WHERE index_name = 'legacy_unresolved_index'");
        } finally {
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }

    public function testItAdmitsEarlyIndexesOnlyAfterTheirTableFilesAreCompleted(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 1);
        $connection = null;

        try {
            IntegrationDatabase::resetSchema($pool);
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', ['--force-yes' => true]);
            $operation = $pool->query(<<<'SQL'
INSERT INTO gar_import_operations (
    source_key, source_path, manifest_hash, status, batch_size, files_total,
    mode, phase, write_mode, indexes_total
) VALUES (
    repeat('a', 64), '/data/gar', repeat('b', 64), 'running', 1, 2,
    'initial-load', 'import', 'direct-copy', 2
)
RETURNING id
SQL)->fetchRow();
            Assert::notNull($operation);
            $operationId = (int) ($operation['id'] ?? 0);
            $readyFileKey = \str_repeat('c', 64);
            $activeFileKey = \str_repeat('d', 64);
            $pool->query(<<<SQL
INSERT INTO gar_import_files (
    operation_id, file_key, relative_path, descriptor_key, table_name,
    file_size, file_mtime, status, completed_at
) VALUES
    (
        {$operationId}, '{$readyFileKey}', 'AS_OPERATION_TYPES.XML', 'operation-types',
        'operation_types', 1, 1, 'completed', now()
    ),
    (
        {$operationId}, '{$activeFileKey}', 'AS_OBJECT_LEVELS.XML', 'object-levels',
        'object_levels', 1, 1, 'running', NULL
    )
SQL);

            $readyIndex = 'gar_q52_ready_idx';
            $activeIndex = 'gar_q52_active_idx';
            $readySql = "CREATE INDEX {$readyIndex} ON public.operation_types USING btree (name)";
            $activeSql = "CREATE INDEX {$activeIndex} ON public.object_levels USING btree (name)";
            $connection = (new GarImportConnectionFactory($dsn))->connect();
            $snapshot = \pg_query_params(
                $connection,
                <<<'SQL'
INSERT INTO gar_import_index_snapshots (
    index_name, table_name, create_sql, operation_id, definition_hash
) VALUES
    ($1, 'operation_types', $2, $3, $4),
    ($5, 'object_levels', $6, $3, $7)
SQL,
                [
                    $readyIndex,
                    $readySql,
                    (string) $operationId,
                    \hash('sha256', $readySql),
                    $activeIndex,
                    $activeSql,
                    \hash('sha256', $activeSql),
                ],
            );
            Assert::true($snapshot !== false);

            $store = new GarInitialLoadStore($connection);
            Assert::true($store->completedSourcePercentAtLeast($operationId, 50));
            Assert::false($store->completedSourcePercentAtLeast($operationId, 51));
            $pending = $store->pendingIndexesForCompletedTables($operationId);
            Assert::same(\count($pending), 1);
            $candidate = $pending[0] ?? null;
            Assert::notNull($candidate);
            Assert::same($candidate->indexName, $readyIndex);
            Assert::same($candidate->tableName, 'operation_types');

            $store->buildIndex($operationId, $readyIndex);
            $state = $pool->query(<<<SQL
SELECT
    (to_regclass('public.{$readyIndex}') IS NOT NULL) AS ready_exists,
    (SELECT status FROM gar_import_index_snapshots WHERE index_name = '{$readyIndex}') AS ready_status,
    (SELECT status FROM gar_import_index_snapshots WHERE index_name = '{$activeIndex}') AS active_status,
    (SELECT index_build_started_at IS NOT NULL FROM gar_import_operations WHERE id = {$operationId}) AS started
SQL)->fetchRow();
            Assert::notNull($state);
            Assert::same($state['ready_exists'] ?? null, true);
            Assert::same($state['ready_status'] ?? null, 'completed');
            Assert::same($state['active_status'] ?? null, 'pending');
            Assert::same($state['started'] ?? null, true);

            Expect::exception(GarImportFailed::class);
            $store->buildIndex($operationId, $activeIndex);
        } finally {
            if ($connection instanceof Connection) {
                \pg_close($connection);
            }
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }

    public function testItRestoresAndValidatesDeferredForeignKeysAfterAnInitialLoad(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 1);
        $representative = \dirname(__DIR__, 3) . '/Fixture/Gar/representative';

        try {
            IntegrationDatabase::resetSchema($pool);
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', ['--force-yes' => true]);
            [$beforeCount, $beforeDigest] = self::foreignKeyDefinitionState($pool);
            [$beforePrimaryCount, $beforePrimaryDigest] = self::primaryKeyDefinitionState($pool);

            [$exitCode, $output] = self::runImport(
                $dsn,
                $representative,
                false,
                true,
                true,
                deferPrimaryKeys: true,
                deferForeignKeys: true,
                overlapTableIndexes: true,
                persistentIndexWorkers: true,
                overlapIndexForeignKeys: true,
            );

            Assert::same($exitCode, 0);
            Assert::true(\str_contains($output, 'primary_keys=deferred'));
            Assert::true(\str_contains($output, 'foreign_keys=deferred'));
            Assert::true(\str_contains($output, 'overlap_index_foreign_keys=yes'));
            Assert::true(\str_contains($output, "foreign_keys_completed={$beforeCount}/{$beforeCount}"));
            $operation = $pool->query(<<<'SQL'
SELECT
    status,
    phase,
    defer_primary_keys,
    defer_foreign_keys,
    foreign_keys_total,
    foreign_keys_completed,
    foreign_key_restore_started_at IS NOT NULL AS restore_started,
    foreign_key_restore_completed_at IS NOT NULL AS restore_completed,
    foreign_key_validation_started_at IS NOT NULL AS validation_started,
    foreign_key_validation_completed_at IS NOT NULL AS validation_completed,
    foreign_key_validation_started_at <= index_build_completed_at AS validation_overlapped_indexes,
    (SELECT count(*) FROM gar_import_foreign_key_snapshots) AS snapshot_backlog,
    (
        SELECT count(*)
        FROM pg_constraint constraint_row
        JOIN pg_class table_class ON table_class.oid = constraint_row.conrelid
        JOIN pg_namespace namespace ON namespace.oid = table_class.relnamespace
        WHERE namespace.nspname = 'public'
          AND constraint_row.contype = 'f'
          AND NOT constraint_row.convalidated
    ) AS unvalidated
FROM gar_import_operations
ORDER BY id DESC
LIMIT 1
SQL)->fetchRow();
            Assert::notNull($operation);
            Assert::same($operation['status'] ?? null, 'completed');
            Assert::same($operation['phase'] ?? null, 'ready');
            Assert::same($operation['defer_primary_keys'] ?? null, true);
            Assert::same($operation['defer_foreign_keys'] ?? null, true);
            Assert::same((int) ($operation['foreign_keys_total'] ?? -1), $beforeCount);
            Assert::same((int) ($operation['foreign_keys_completed'] ?? -1), $beforeCount);
            Assert::same($operation['restore_started'] ?? null, true);
            Assert::same($operation['restore_completed'] ?? null, true);
            Assert::same($operation['validation_started'] ?? null, true);
            Assert::same($operation['validation_completed'] ?? null, true);
            Assert::same($operation['validation_overlapped_indexes'] ?? null, true);
            Assert::same((int) ($operation['snapshot_backlog'] ?? -1), 0);
            Assert::same((int) ($operation['unvalidated'] ?? -1), 0);
            [$afterCount, $afterDigest] = self::foreignKeyDefinitionState($pool);
            Assert::same($afterCount, $beforeCount);
            Assert::same($afterDigest, $beforeDigest);
            [$afterPrimaryCount, $afterPrimaryDigest] = self::primaryKeyDefinitionState($pool);
            Assert::same($afterPrimaryCount, $beforePrimaryCount);
            Assert::same($afterPrimaryDigest, $beforePrimaryDigest);
        } finally {
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }

    public function testItResumesDirectCopyFromTheCommittedCanonicalCheckpoint(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 1);
        $partial = \dirname(__DIR__, 3) . '/Fixture/Gar/partial';

        try {
            IntegrationDatabase::resetSchema($pool);
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', ['--force-yes' => true]);

            [$exitCode] = self::runImport($dsn, $partial, false, true, true);
            Assert::same($exitCode, 1);
            $failed = $pool->query(<<<'SQL'
SELECT
    operation.id,
    operation.status,
    operation.phase,
    operation.write_mode,
    operation.attempt,
    operation.rows_processed,
    (SELECT count(*) FROM operation_types WHERE id = 20) AS canonical_rows,
    (SELECT count(*) FROM gar_import_index_snapshots) AS snapshot_backlog
FROM gar_import_operations operation
ORDER BY operation.id DESC
LIMIT 1
SQL)->fetchRow();
            Assert::notNull($failed);
            Assert::same($failed['status'] ?? null, 'failed');
            Assert::same($failed['phase'] ?? null, 'import');
            Assert::same($failed['write_mode'] ?? null, 'direct-copy');
            Assert::same((int) ($failed['attempt'] ?? -1), 1);
            Assert::same((int) ($failed['rows_processed'] ?? -1), 1);
            Assert::same((int) ($failed['canonical_rows'] ?? -1), 1);
            Assert::true((int) ($failed['snapshot_backlog'] ?? 0) > 0);

            [$exitCode] = self::runImport($dsn, $partial, false, true, true);
            Assert::same($exitCode, 1);
            $resumed = $pool->query(<<<'SQL'
SELECT
    id,
    attempt,
    rows_processed,
    (SELECT count(*) FROM operation_types WHERE id = 20) AS canonical_rows
FROM gar_import_operations
ORDER BY id DESC
LIMIT 1
SQL)->fetchRow();
            Assert::notNull($resumed);
            Assert::same($resumed['id'] ?? null, $failed['id'] ?? null);
            Assert::same((int) ($resumed['attempt'] ?? -1), 2);
            Assert::same((int) ($resumed['rows_processed'] ?? -1), 1);
            Assert::same((int) ($resumed['canonical_rows'] ?? -1), 1);
        } finally {
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }

    public function testItRunsTheSelectedObjectParametersFileThroughTheBoundedPipeline(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 1);
        $source = \dirname(__DIR__, 3) . '/Fixture/Gar/pipeline';

        try {
            IntegrationDatabase::resetSchema($pool);
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', ['--force-yes' => true]);

            [$exitCode, $output] = self::runImport(
                $dsn,
                $source,
                false,
                true,
                true,
                'AS_HOUSES_PARAMS_20261104.XML',
                2,
                pipelineParsers: 2,
                workers: 4,
                pipelineFusedRanges: true,
                overlapTableIndexes: true,
                persistentIndexWorkers: true,
            );

            Assert::same($exitCode, 0);
            Assert::true(\str_contains($output, 'status=completed'));
            Assert::true(\str_contains($output, 'pipeline=AS_HOUSES_PARAMS_20261104.XML'));
            Assert::true(\str_contains($output, 'pipeline_writers=2'));
            Assert::true(\str_contains($output, 'pipeline_fused_ranges=yes'));
            Assert::true(\str_contains($output, 'overlap_table_indexes=yes'));
            Assert::true(\str_contains($output, 'overlap_index_workers=1'));
            Assert::true(\str_contains($output, 'persistent_index_workers=yes'));
            $state = $pool->query(<<<'SQL'
SELECT
    operation.status,
    operation.pipeline_writers,
    operation.pipeline_batches,
    operation.pipeline_bytes,
    operation.pipeline_peak_in_flight_batches,
    operation.pipeline_peak_in_flight_bytes,
    (SELECT count(*) FROM object_params) AS object_params,
    (SELECT count(*) FROM gar_import_file_batches) AS pending_ranges
FROM gar_import_operations operation
ORDER BY operation.id DESC
LIMIT 1
SQL)->fetchRow();
            Assert::notNull($state);
            Assert::same($state['status'] ?? null, 'completed');
            Assert::same((int) ($state['pipeline_writers'] ?? -1), 2);
            Assert::same((int) ($state['pipeline_batches'] ?? -1), 3);
            Assert::true((int) ($state['pipeline_bytes'] ?? 0) > 0);
            Assert::true((int) ($state['pipeline_peak_in_flight_batches'] ?? 0) >= 1);
            Assert::true((int) ($state['pipeline_peak_in_flight_batches'] ?? 0) <= 2);
            Assert::true((int) ($state['pipeline_peak_in_flight_bytes'] ?? 0) > 0);
            Assert::same((int) ($state['object_params'] ?? -1), 4);
            Assert::same((int) ($state['pending_ranges'] ?? -1), 0);
        } finally {
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }

    public function testItAutomaticallyPipelinesEveryCompatibleFileInEveryDataPhase(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 1);
        $source = \dirname(__DIR__, 3) . '/Fixture/Gar/pipeline';

        try {
            IntegrationDatabase::resetSchema($pool);
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', ['--force-yes' => true]);

            [$exitCode, $output] = self::runImport(
                $dsn,
                $source,
                false,
                true,
                true,
                'auto',
                2,
                pipelineParsers: 2,
                workers: 4,
                pipelineFusedRanges: true,
                pipelineFileConcurrency: 2,
                pipelineTableAwareFiles: true,
            );

            Assert::same($exitCode, 0);
            Assert::true(\str_contains($output, 'status=completed'));
            Assert::true(\str_contains($output, 'pipeline=auto:3'));
            Assert::true(\str_contains($output, 'pipeline_parsers=2'));
            Assert::true(\str_contains($output, 'pipeline_fused_ranges=yes'));
            Assert::true(\str_contains($output, 'pipeline_file_concurrency=2'));
            Assert::true(\str_contains($output, 'pipeline_table_aware_files=yes'));
            $state = $pool->query(<<<'SQL'
SELECT
    operation.status,
    operation.pipeline_file_key,
    operation.pipeline_batches,
    operation.pipeline_peak_in_flight_batches,
    operation.pipeline_peak_in_flight_bytes,
    (SELECT count(*) FROM gar_import_files WHERE pipeline_enabled) AS pipeline_files,
    (SELECT count(DISTINCT descriptor_key) FROM gar_import_files WHERE pipeline_enabled) AS descriptors,
    (SELECT count(*) FROM gar_import_file_batches) AS pending_ranges,
    (SELECT count(*) FROM object_params) AS object_params
FROM gar_import_operations operation
ORDER BY operation.id DESC
LIMIT 1
SQL)->fetchRow();
            Assert::notNull($state);
            Assert::same($state['status'] ?? null, 'completed');
            Assert::same((int) ($state['pipeline_files'] ?? -1), 3);
            Assert::same((int) ($state['descriptors'] ?? -1), 2);
            Assert::same((int) ($state['pipeline_batches'] ?? -1), 5);
            Assert::true((int) ($state['pipeline_peak_in_flight_batches'] ?? 0) >= 1);
            Assert::true((int) ($state['pipeline_peak_in_flight_batches'] ?? 0) <= 2);
            Assert::true((int) ($state['pipeline_peak_in_flight_bytes'] ?? 0) > 0);
            Assert::same((int) ($state['pending_ranges'] ?? -1), 0);
            Assert::same((int) ($state['object_params'] ?? -1), 4);
            Assert::true(\is_string($state['pipeline_file_key'] ?? null));
        } finally {
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }

    public function testItReusesAProcessConnectionAndRejectsOverlappingCommittedRanges(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 1);
        $connection = null;
        $workers = null;

        try {
            IntegrationDatabase::resetSchema($pool);
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', ['--force-yes' => true]);
            $fileKey = \str_repeat('f', 64);
            $operation = $pool->query(<<<SQL
INSERT INTO gar_import_operations (
    source_key, source_path, manifest_hash, status, batch_size, files_total,
    mode, phase, write_mode, pipeline_file_key, pipeline_writers
) VALUES (
    repeat('a', 64), '/data/gar', repeat('b', 64), 'running', 1, 1,
    'initial-load', 'import', 'direct-copy', '{$fileKey}', 1
)
RETURNING id
SQL)->fetchRow();
            Assert::notNull($operation);
            $operationId = (int) ($operation['id'] ?? 0);
            $pool->query(<<<SQL
INSERT INTO gar_import_files (
    operation_id, file_key, relative_path, descriptor_key, table_name, file_size, file_mtime, status,
    pipeline_enabled
) VALUES (
    {$operationId}, '{$fileKey}', 'AS_OPERATION_TYPES.XML', 'operation-types',
    'operation_types', 1, 1, 'running', true
)
SQL);

            $workers = new ContextWorkerPool(
                1,
                new ContextWorkerFactory(
                    contextFactory: new ProcessContextFactory(workingDirectory: \dirname(__DIR__, 4)),
                ),
            );
            $copyTarget = 'operation_types (id, name, is_active, update_date, start_date, end_date)';
            $second = self::runBatchTask(
                $workers,
                new GarDirectCopyBatchTask(
                    $dsn,
                    $operationId,
                    $fileKey,
                    $copyTarget,
                    ["21\tSecond\tt\t2026-11-04\t1900-01-01\t2079-06-06\n"],
                    2,
                    2,
                ),
            );
            Assert::same($second->processedRows, 0);
            $overlapRejected = false;
            try {
                self::runBatchTask(
                    $workers,
                    new GarDirectCopyBatchTask(
                        $dsn,
                        $operationId,
                        $fileKey,
                        $copyTarget,
                        ["22\tOverlap\tt\t2026-11-04\t1900-01-01\t2079-06-06\n"],
                        2,
                        2,
                    ),
                );
            } catch (Throwable) {
                $overlapRejected = true;
            }
            Assert::true($overlapRejected);

            $first = self::runBatchTask(
                $workers,
                new GarDirectCopyBatchTask(
                    $dsn,
                    $operationId,
                    $fileKey,
                    $copyTarget,
                    ["20\tFirst\tt\t2026-11-04\t1900-01-01\t2079-06-06\n"],
                    1,
                    1,
                ),
            );
            Assert::same($first->processedRows, 2);
            Assert::same($first->backendPid, $second->backendPid);
            $workers->kill();

            $connection = (new GarImportConnectionFactory($dsn))->connect();
            $store = new PostgresGarImportStore($connection);
            $store->completePipelineFile($operationId, $fileKey);
            $state = $pool->query(<<<SQL
SELECT
    file.processed_rows,
    file.merged_rows,
    file.status,
    operation.pipeline_batches,
    operation.pipeline_bytes,
    (SELECT count(*) FROM gar_import_file_batches) AS pending_ranges,
    (SELECT count(*) FROM operation_types WHERE id = 22) AS rolled_back_overlap,
    (SELECT count(*) FROM pg_stat_activity WHERE pid = {$first->backendPid}) AS worker_connections
FROM gar_import_files file
JOIN gar_import_operations operation ON operation.id = file.operation_id
WHERE file.operation_id = {$operationId} AND file.file_key = '{$fileKey}'
SQL)->fetchRow();
            Assert::notNull($state);
            Assert::same((int) ($state['processed_rows'] ?? -1), 2);
            Assert::same((int) ($state['merged_rows'] ?? -1), 2);
            Assert::same($state['status'] ?? null, 'completed');
            Assert::same((int) ($state['pipeline_batches'] ?? -1), 2);
            Assert::true((int) ($state['pipeline_bytes'] ?? 0) > 0);
            Assert::same((int) ($state['pending_ranges'] ?? -1), 0);
            Assert::same((int) ($state['rolled_back_overlap'] ?? -1), 0);
            Assert::same((int) ($state['worker_connections'] ?? -1), 0);
        } finally {
            if ($workers instanceof ContextWorkerPool && $workers->isRunning()) {
                $workers->kill();
            }
            if ($connection instanceof Connection) {
                \pg_close($connection);
            }
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }

    public function testItStreamsOneRangeThroughMultipleAtomicCopyCheckpoints(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 1);
        $connection = null;

        try {
            IntegrationDatabase::resetSchema($pool);
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', ['--force-yes' => true]);
            $fileKey = \str_repeat('f', 64);
            $operation = $pool->query(<<<SQL
INSERT INTO gar_import_operations (
    source_key, source_path, manifest_hash, status, batch_size, files_total,
    mode, phase, write_mode, pipeline_file_key, pipeline_writers
) VALUES (
    repeat('a', 64), '/data/gar', repeat('b', 64), 'running', 3, 1,
    'initial-load', 'import', 'direct-copy', '{$fileKey}', 1
)
RETURNING id
SQL)->fetchRow();
            Assert::notNull($operation);
            $operationId = (int) ($operation['id'] ?? 0);
            $pool->query(<<<SQL
INSERT INTO gar_import_files (
    operation_id, file_key, relative_path, descriptor_key, table_name, file_size, file_mtime, status,
    pipeline_enabled
) VALUES (
    {$operationId}, '{$fileKey}', 'AS_OPERATION_TYPES.XML', 'operation-types',
    'operation_types', 1, 1, 'running', true
)
SQL);

            $encodedRows = [
                1 => "20\tA\tt\t2026-11-04\t1900-01-01\t2079-06-06\n",
                2 => "21\tB\tt\t2026-11-04\t1900-01-01\t2079-06-06\n",
                3 => "22\tC\tt\t2026-11-04\t1900-01-01\t2079-06-06\n",
            ];
            $connection = (new GarImportConnectionFactory($dsn))->connect();
            $result = new GarDirectCopyStreamWriter(
                $connection,
                transportBytes: 32,
                transactionBytes: 64,
            )->write(
                operationId: $operationId,
                fileKey: $fileKey,
                copyTarget: 'operation_types (id, name, is_active, update_date, start_date, end_date)',
                rows: (static function () use ($encodedRows): \Generator {
                    yield from $encodedRows;
                })(),
                expectedFirstRow: 1,
                expectedLastRow: 3,
            );

            Assert::same($result->processedRows, 3);
            Assert::same($result->rows, 3);
            Assert::same($result->encodedBytes, \array_sum(\array_map(\strlen(...), $encodedRows)));
            Assert::same($result->transactions, 3);

            $invalidCopyRejected = false;
            try {
                new GarDirectCopyStreamWriter(
                    connection: $connection,
                    transactionBytes: 64,
                )->write(
                    operationId: $operationId,
                    fileKey: $fileKey,
                    copyTarget: 'operation_types (id, name, is_active, update_date, start_date, end_date)',
                    rows: [4 => "22\tDuplicate\tt\t2026-11-04\t1900-01-01\t2079-06-06\n"],
                    expectedFirstRow: 4,
                    expectedLastRow: 4,
                );
            } catch (GarImportFailed) {
                $invalidCopyRejected = true;
            }
            Assert::true($invalidCopyRejected);
            $connectionProbe = \pg_query($connection, 'SELECT 1');
            Assert::true($connectionProbe instanceof \PgSql\Result);
            \pg_free_result($connectionProbe);

            $state = $pool->query(<<<SQL
SELECT
    file.processed_rows,
    file.merged_rows,
    operation.rows_processed,
    operation.rows_merged,
    operation.pipeline_batches,
    operation.pipeline_bytes,
    (SELECT count(*) FROM gar_import_file_batches) AS pending_ranges,
    (SELECT count(*) FROM operation_types WHERE id BETWEEN 20 AND 22) AS copied_rows
FROM gar_import_files file
JOIN gar_import_operations operation ON operation.id = file.operation_id
WHERE file.operation_id = {$operationId} AND file.file_key = '{$fileKey}'
SQL)->fetchRow();
            Assert::notNull($state);
            Assert::same((int) ($state['processed_rows'] ?? -1), 3);
            Assert::same((int) ($state['merged_rows'] ?? -1), 3);
            Assert::same((int) ($state['rows_processed'] ?? -1), 3);
            Assert::same((int) ($state['rows_merged'] ?? -1), 3);
            Assert::same((int) ($state['pipeline_batches'] ?? -1), 3);
            Assert::same((int) ($state['pipeline_bytes'] ?? -1), $result->encodedBytes);
            Assert::same((int) ($state['pending_ranges'] ?? -1), 0);
            Assert::same((int) ($state['copied_rows'] ?? -1), 3);
        } finally {
            if ($connection instanceof Connection) {
                \pg_close($connection);
            }
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }

    /** @return array{int, string} */
    private static function runRawImport(
        string $dsn,
        string $source,
        string $pipelineFile,
        int $primaryWorkers = 2,
        int $childWorkersPerPrimary = 1,
        bool $deferPrimaryKeys = false,
    ): array {
        $output = new BufferedOutput();
        $input = [
            'command' => 'gar:import:raw',
            'source' => $source,
            '--batch-size' => '1',
            '--primary-workers' => (string) $primaryWorkers,
            '--child-workers-per-primary' => (string) $childWorkersPerPrimary,
            '--pipeline-file' => $pipelineFile,
            '--fragment-mib' => '8',
            '--defer-foreign-keys' => true,
            '--no-ansi' => true,
        ];
        if ($deferPrimaryKeys) {
            $input['--defer-primary-keys'] = true;
        }
        $exitCode = Dic::run(
            new CliRuntimeModule(new EnvironmentSnapshot([
                'APP_NAME' => 'geo-registry-integration',
                'APP_VERSION' => 'test',
                'DATABASE_DSN' => $dsn,
            ])),
            static fn(CliRuntime $runtime): int => $runtime->run(new ArrayInput($input), $output),
        );

        return [$exitCode, $output->fetch()];
    }

    /** @return array{int, string} */
    private static function runImport(
        string $dsn,
        string $source,
        bool $restart,
        bool $initialLoad = false,
        bool $directCopy = false,
        ?string $pipelineFile = null,
        ?int $pipelineWriters = null,
        bool $deferForeignKeys = false,
        ?int $pipelineParsers = null,
        int $workers = 2,
        bool $pipelineFusedRanges = false,
        int $pipelineFileConcurrency = 1,
        bool $pipelineTableAwareFiles = false,
        bool $overlapTableIndexes = false,
        bool $persistentIndexWorkers = false,
        bool $deferPrimaryKeys = false,
        bool $overlapIndexForeignKeys = false,
    ): array {
        $output = new BufferedOutput();
        $input = [
            'command' => 'gar:import',
            'source' => $source,
            '--batch-size' => '1',
            '--workers' => (string) $workers,
            '--no-ansi' => true,
        ];
        if ($restart) {
            $input['--restart'] = true;
        }
        if ($initialLoad) {
            $input['--initial-load'] = true;
        }
        if ($directCopy) {
            $input['--direct-copy'] = true;
        }
        if ($pipelineFile !== null) {
            $input['--pipeline-file'] = $pipelineFile;
        }
        if ($pipelineWriters !== null) {
            $input['--pipeline-writers'] = (string) $pipelineWriters;
        }
        if ($pipelineParsers !== null) {
            $input['--pipeline-parsers'] = (string) $pipelineParsers;
        }
        if ($pipelineFusedRanges) {
            $input['--pipeline-fused-ranges'] = true;
        }
        if ($pipelineFileConcurrency !== 1) {
            $input['--pipeline-file-concurrency'] = (string) $pipelineFileConcurrency;
        }
        if ($pipelineTableAwareFiles) {
            $input['--pipeline-table-aware-files'] = true;
        }
        if ($overlapTableIndexes) {
            $input['--overlap-table-indexes'] = true;
        }
        if ($persistentIndexWorkers) {
            $input['--persistent-index-workers'] = true;
        }
        if ($deferPrimaryKeys) {
            $input['--defer-primary-keys'] = true;
        }
        if ($overlapIndexForeignKeys) {
            $input['--overlap-index-foreign-keys'] = true;
        }
        if ($deferForeignKeys) {
            $input['--defer-foreign-keys'] = true;
        }

        $exitCode = Dic::run(
            new CliRuntimeModule(new EnvironmentSnapshot([
                'APP_NAME' => 'geo-registry-integration',
                'APP_VERSION' => 'test',
                'DATABASE_DSN' => $dsn,
            ])),
            static fn(CliRuntime $runtime): int => $runtime->run(new ArrayInput($input), $output),
        );

        return [$exitCode, $output->fetch()];
    }

    private static function runBatchTask(
        ContextWorkerPool $workers,
        GarDirectCopyBatchTask $task,
    ): GarDirectCopyBatchResult {
        $timeout = new TimeoutCancellation(10);
        /** @var \Amp\Parallel\Worker\Execution<GarDirectCopyBatchResult, never, never> $execution */
        $execution = $workers->submit($task, $timeout);

        return $execution->getFuture()->await($timeout);
    }

    private static function secondaryIndexCount(\Amp\Postgres\PostgresConnectionPool $pool): int
    {
        $row = $pool->query(<<<'SQL'
SELECT count(*) AS value
FROM pg_index indexes
JOIN pg_class index_class ON index_class.oid = indexes.indexrelid
JOIN pg_class table_class ON table_class.oid = indexes.indrelid
JOIN pg_namespace namespace ON namespace.oid = table_class.relnamespace
LEFT JOIN pg_constraint constraint_row ON constraint_row.conindid = indexes.indexrelid
WHERE namespace.nspname = 'public'
  AND table_class.relname IN (
      'address_object_divisions', 'address_object_types', 'address_objects',
      'administrative_reestr_hierarchy', 'apartment_types', 'apartments',
      'car_places', 'change_history', 'house_addr_types', 'house_types', 'houses',
      'municipal_reestr_hierarchy', 'normative_doc_kinds', 'normative_doc_types',
      'normative_docs', 'object_levels', 'object_params', 'operation_types',
      'param_types', 'reestr_objects', 'room_types', 'rooms', 'steads'
  )
  AND index_class.relkind = 'i'
  AND NOT indexes.indisprimary
  AND NOT indexes.indisunique
  AND NOT indexes.indisexclusion
  AND constraint_row.oid IS NULL
SQL)->fetchRow();
        Assert::notNull($row);

        return (int) ($row['value'] ?? -1);
    }

    /** @return array{int, string} */
    private static function foreignKeyDefinitionState(\Amp\Postgres\PostgresConnectionPool $pool): array
    {
        $row = $pool->query(<<<'SQL'
SELECT
    count(*) AS value,
    md5(string_agg(
        table_class.relname || '.' || constraint_row.conname || '='
            || pg_get_constraintdef(constraint_row.oid, false),
        E'\n' ORDER BY table_class.relname, constraint_row.conname
    )) AS digest
FROM pg_constraint constraint_row
JOIN pg_class table_class ON table_class.oid = constraint_row.conrelid
JOIN pg_namespace namespace ON namespace.oid = table_class.relnamespace
WHERE namespace.nspname = 'public'
  AND table_class.relname IN (
      'address_object_divisions', 'address_object_types', 'address_objects',
      'administrative_reestr_hierarchy', 'apartment_types', 'apartments',
      'car_places', 'change_history', 'house_addr_types', 'house_types', 'houses',
      'municipal_reestr_hierarchy', 'normative_doc_kinds', 'normative_doc_types',
      'normative_docs', 'object_levels', 'object_params', 'operation_types',
      'param_types', 'reestr_objects', 'room_types', 'rooms', 'steads'
  )
  AND constraint_row.contype = 'f'
  AND constraint_row.convalidated
SQL)->fetchRow();
        Assert::notNull($row);
        $digest = $row['digest'] ?? null;
        Assert::true(\is_string($digest));

        return [(int) ($row['value'] ?? -1), $digest];
    }

    /** @return array{int, string} */
    private static function primaryKeyDefinitionState(\Amp\Postgres\PostgresConnectionPool $pool): array
    {
        $row = $pool->query(<<<'SQL'
SELECT
    count(*) AS value,
    md5(string_agg(
        table_class.relname || '.' || constraint_row.conname || '='
            || pg_get_constraintdef(constraint_row.oid, false) || '='
            || pg_get_indexdef(constraint_row.conindid),
        E'\n' ORDER BY table_class.relname, constraint_row.conname
    )) AS digest
FROM pg_constraint constraint_row
JOIN pg_class table_class ON table_class.oid = constraint_row.conrelid
JOIN pg_namespace namespace ON namespace.oid = table_class.relnamespace
WHERE namespace.nspname = 'public'
  AND table_class.relname IN (
      'address_object_divisions', 'address_object_types', 'address_objects',
      'administrative_reestr_hierarchy', 'apartment_types', 'apartments',
      'car_places', 'change_history', 'house_addr_types', 'house_types', 'houses',
      'municipal_reestr_hierarchy', 'normative_doc_kinds', 'normative_doc_types',
      'normative_docs', 'object_levels', 'object_params', 'operation_types',
      'param_types', 'reestr_objects', 'room_types', 'rooms', 'steads'
  )
  AND constraint_row.contype = 'p'
  AND constraint_row.convalidated
SQL)->fetchRow();
        Assert::notNull($row);
        $digest = $row['digest'] ?? null;
        Assert::true(\is_string($digest));

        return [(int) ($row['value'] ?? -1), $digest];
    }
}
