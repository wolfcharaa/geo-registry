<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\AddressRegistry;

use function Amp\async;
use function Amp\ByteStream\buffer;

use Amp\ByteStream\ReadableStream;

use function Amp\delay;

use Amp\Future;
use Amp\Postgres\PostgresConnectionPool;
use Amp\Process\Process;
use Amp\TimeoutCancellation;
use App\Infrastructure\Postgres\PostgresPoolFactory;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarImportConnectionFactory;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarInitialLoadStore;
use App\Tests\Support\Database\IntegrationDatabase;
use PgSql\Connection;
use RuntimeException;
use Testo\Assert;
use Testo\Test;
use Throwable;

#[Test]
final class GarPipelineRecoveryIntegrationTest
{
    private const string PIPELINE_FILE = 'AS_HOUSES_PARAMS_20261104.XML';

    private const string VALIDATION_TABLE = 'object_params';

    private const string VALIDATION_CONSTRAINT = 'object_params_type_id_fkey';

    private const int TARGET_ROWS = 400;

    public function testItRecoversAfterSupervisorTerminationWithInflightBatches(): void
    {
        $this->exerciseRecovery('supervisor-sigterm', true, true, true);
    }

    public function testItResumesTheWorkOrchestratedRawBoundaryAfterSupervisorTermination(): void
    {
        $this->exerciseWorkOrchestratedRawRecovery('raw-supervisor-sigterm');
    }

    public function testItResumesTheWorkOrchestratedRawBoundaryAfterManagedChildCrash(): void
    {
        $this->exerciseWorkOrchestratedRawRecovery('raw-managed-child-sigkill');
    }

    private function exerciseWorkOrchestratedRawRecovery(string $fault): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 3);
        $source = self::createSourceFixture();
        $process = null;
        $stdout = null;
        $stderr = null;

        try {
            IntegrationDatabase::resetSchema($pool);
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', ['--force-yes' => true]);
            self::installSlowInsertTrigger($pool);
            [$process, $stdout, $stderr] = self::startRawImport($dsn, $source, 10, 2, 2);

            self::awaitPipelineCopyBackends($pool, 1);
            $workerPids = self::awaitPipelineWriters($process->getPid());
            if ($fault === 'raw-supervisor-sigterm') {
                $process->signal(SIGTERM);
            } elseif ($fault === 'raw-managed-child-sigkill') {
                $managedChildPids = self::awaitActiveManagedChildWriters($pool);
                self::killProcess($managedChildPids[0]);
            } else {
                throw new RuntimeException("Unknown work-orchestrated raw recovery fault: {$fault}");
            }
            $exitCode = $process->join(new TimeoutCancellation(20));
            $firstOutput = $stdout->await();
            $firstErrors = $stderr->await();
            Assert::true(
                $exitCode !== 0,
                self::processReport($fault, $firstOutput, $firstErrors),
            );
            Assert::true(\str_contains($firstOutput, 'diagnostic[0]={'), $firstOutput);
            if ($fault === 'raw-managed-child-sigkill') {
                Assert::true(\str_contains(
                    $firstOutput,
                    'GAR raw work-orchestrator run failed: Amp\\Parallel\\Context\\ContextException:',
                ), $firstOutput);
                Assert::true(\str_contains($firstOutput, '"kind":"worker_crash"'), $firstOutput);
            }
            self::awaitNoPipelineWriters($workerPids);

            $failed = $pool->query(<<<'SQL'
SELECT id, status, attempt, phase
FROM gar_import_operations
ORDER BY id DESC
LIMIT 1
SQL)->fetchRow();
            Assert::notNull($failed);
            Assert::same($failed['status'] ?? null, 'failed');
            Assert::same((int) ($failed['attempt'] ?? 0), 1);
            Assert::same($failed['phase'] ?? null, 'import');
            $operationId = (int) ($failed['id'] ?? 0);
            Assert::true($operationId > 0);

            self::dropSlowInsertTrigger($pool);
            [$resume, $resumeStdout, $resumeStderr] = self::startRawImport($dsn, $source, 7, 3, 3);
            try {
                $resumeExitCode = $resume->join(new TimeoutCancellation(90));
            } finally {
                $resume->kill();
            }
            $resumeOutput = $resumeStdout->await();
            $resumeErrors = $resumeStderr->await();
            Assert::same(
                $resumeExitCode,
                0,
                self::processReport("{$fault}-resume", $resumeOutput, $resumeErrors),
            );
            Assert::true(\str_contains($resumeOutput, 'status=raw-completed'), $resumeOutput);
            Assert::true(\str_contains($resumeOutput, 'resumed=yes'), $resumeOutput);
            self::assertRawBoundaryState(
                $pool,
                $operationId,
                attempt: 2,
                batchSize: 7,
                pipelineWriters: 3,
            );
        } finally {
            if ($process instanceof Process) {
                $process->kill();
            }
            if ($stdout instanceof Future) {
                try {
                    $stdout->await(new TimeoutCancellation(2));
                } catch (Throwable) {
                }
            }
            if ($stderr instanceof Future) {
                try {
                    $stderr->await(new TimeoutCancellation(2));
                } catch (Throwable) {
                }
            }
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
            self::removeSourceFixture($source);
        }
    }

    private static function assertRawBoundaryState(
        PostgresConnectionPool $pool,
        int $operationId,
        int $attempt,
        int $batchSize,
        int $pipelineWriters,
    ): void {
        $state = $pool->query(<<<'SQL'
SELECT
    operation.id,
    operation.status,
    operation.attempt,
    operation.phase,
    operation.batch_size,
    operation.pipeline_writers,
    operation.files_total,
    operation.files_completed,
    operation.rows_processed,
    operation.rows_merged,
    operation.indexes_total,
    operation.indexes_completed,
    operation.foreign_keys_total,
    operation.foreign_keys_completed,
    (SELECT count(*) FROM gar_import_file_batches) AS pending_ranges,
    (SELECT count(*) FROM object_params) AS object_params,
    (SELECT count(DISTINCT id) FROM object_params) AS distinct_object_params,
    (SELECT md5(string_agg(id::text || ':' || value, ',' ORDER BY id)) FROM object_params) AS object_params_digest
FROM gar_import_operations operation
ORDER BY operation.id DESC
LIMIT 1
SQL)->fetchRow();
        Assert::notNull($state);
        Assert::same((int) ($state['id'] ?? 0), $operationId);
        Assert::same($state['status'] ?? null, 'running');
        Assert::same((int) ($state['attempt'] ?? 0), $attempt);
        Assert::same($state['phase'] ?? null, 'import');
        Assert::same((int) ($state['batch_size'] ?? 0), $batchSize);
        Assert::same((int) ($state['pipeline_writers'] ?? 0), $pipelineWriters);
        Assert::same((int) ($state['files_total'] ?? 0), 5);
        Assert::same((int) ($state['files_completed'] ?? 0), 5);
        Assert::same((int) ($state['rows_processed'] ?? 0), self::TARGET_ROWS + 4);
        Assert::same((int) ($state['rows_merged'] ?? 0), self::TARGET_ROWS + 4);
        Assert::true((int) ($state['indexes_total'] ?? 0) > 0);
        Assert::same((int) ($state['indexes_completed'] ?? -1), 0);
        Assert::true((int) ($state['foreign_keys_total'] ?? 0) > 0);
        Assert::same((int) ($state['foreign_keys_completed'] ?? -1), 0);
        Assert::same((int) ($state['pending_ranges'] ?? -1), 0);
        Assert::same((int) ($state['object_params'] ?? 0), self::TARGET_ROWS + 1);
        Assert::same((int) ($state['distinct_object_params'] ?? 0), self::TARGET_ROWS + 1);
        Assert::same($state['object_params_digest'] ?? null, self::expectedDigest());
    }

    public function testItSelfHealsAfterAnInflightBatchWriterIsKilled(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 3);
        $source = self::createSourceFixture();
        $process = null;
        $stdout = null;
        $stderr = null;

        try {
            IntegrationDatabase::resetSchema($pool);
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', ['--force-yes' => true]);
            self::installSlowInsertTrigger($pool);
            [$process, $stdout, $stderr] = self::startImport($dsn, $source, 10, 2, 2, false);

            self::awaitPipelineCopyBackends($pool, 2);
            $writerPids = self::awaitPipelineWriters($process->getPid());
            self::killProcess($writerPids[0]);
            $exitCode = $process->join(new TimeoutCancellation(90));
            $output = $stdout->await();
            $errors = $stderr->await();
            Assert::same($exitCode, 0, self::processReport('writer-sigkill', $output, $errors));
            Assert::true(\str_contains($output, 'resumed=no'), $output);
            self::awaitNoPipelineWriters($writerPids);

            $operation = $pool->query('SELECT id FROM gar_import_operations ORDER BY id DESC LIMIT 1')->fetchRow();
            Assert::notNull($operation);
            self::assertCompletedState($pool, (int) ($operation['id'] ?? 0), 1, 10, 2, false);
        } finally {
            if ($process instanceof Process) {
                $process->kill();
            }
            if ($stdout instanceof Future) {
                try {
                    $stdout->await(new TimeoutCancellation(2));
                } catch (Throwable) {
                }
            }
            if ($stderr instanceof Future) {
                try {
                    $stderr->await(new TimeoutCancellation(2));
                } catch (Throwable) {
                }
            }
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
            self::removeSourceFixture($source);
        }
    }

    public function testItRecoversAfterPostgresTerminatesAWriterBackend(): void
    {
        $this->exerciseRecovery('postgres-backend', false);
    }

    public function testItResumesAForeignKeyWhoseValidationWorkerWasTerminated(): void
    {
        $this->exerciseForeignKeyValidationRecovery(false);
    }

    public function testItResumesAnOverlappedForeignKeyWhoseValidationWorkerWasTerminated(): void
    {
        $this->exerciseForeignKeyValidationRecovery(true);
    }

    private function exerciseForeignKeyValidationRecovery(bool $overlapIndexForeignKeys): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 3);
        $storeConnection = null;
        $lockConnection = null;
        $process = null;
        $stdout = null;
        $stderr = null;

        try {
            IntegrationDatabase::resetSchema($pool);
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', ['--force-yes' => true]);
            $storeConnection = (new GarImportConnectionFactory($dsn))->connect();
            $lockConnection = (new GarImportConnectionFactory($dsn))->connect();
            $phase = $overlapIndexForeignKeys ? 'index-build' : 'foreign-key-validate';

            $definition = self::foreignKeyDefinition($storeConnection);
            self::execute($storeConnection, 'BEGIN');
            $operation = self::row(self::execute(
                $storeConnection,
                <<<'SQL'
INSERT INTO gar_import_operations (
    source_key, source_path, manifest_hash, status, batch_size, mode, phase, write_mode,
    defer_foreign_keys, foreign_keys_total
) VALUES (
    repeat('c', 64), '/data/gar-validation-recovery', repeat('d', 64), 'running', 50000,
    'initial-load', $1, 'direct-copy', true, 1
)
RETURNING id
SQL,
                [$phase],
            ));
            $operationId = (int) ($operation['id'] ?? 0);
            Assert::true($operationId > 0);
            self::execute(
                $storeConnection,
                'ALTER TABLE public.' . self::VALIDATION_TABLE
                    . ' DROP CONSTRAINT ' . self::VALIDATION_CONSTRAINT,
            );
            self::execute(
                $storeConnection,
                'ALTER TABLE public.' . self::VALIDATION_TABLE
                    . ' ADD CONSTRAINT ' . self::VALIDATION_CONSTRAINT . ' ' . $definition . ' NOT VALID',
            );
            self::execute(
                $storeConnection,
                <<<'SQL'
INSERT INTO gar_import_foreign_key_snapshots (
    operation_id, table_name, constraint_name, definition_sql, definition_hash, status
) VALUES ($1, $2, $3, $4, $5, 'restored')
SQL,
                [
                    (string) $operationId,
                    self::VALIDATION_TABLE,
                    self::VALIDATION_CONSTRAINT,
                    $definition,
                    \hash('sha256', $definition),
                ],
            );
            if ($overlapIndexForeignKeys) {
                self::execute(
                    $storeConnection,
                    <<<'SQL'
UPDATE gar_import_operations
SET foreign_key_restore_started_at = now(),
    foreign_key_restore_completed_at = now(),
    foreign_key_validation_started_at = now()
WHERE id = $1
SQL,
                    [(string) $operationId],
                );
            }
            self::execute($storeConnection, 'COMMIT');

            self::execute($lockConnection, 'BEGIN');
            self::execute(
                $lockConnection,
                'LOCK TABLE public.' . self::VALIDATION_TABLE . ' IN ACCESS EXCLUSIVE MODE',
            );
            [$process, $stdout, $stderr] = self::startForeignKeyWorker($dsn, $operationId);
            self::awaitBlockedForeignKeyValidation($pool, $operationId);

            $process->signal(SIGTERM);
            $exitCode = $process->join(new TimeoutCancellation(20));
            $firstOutput = $stdout->await();
            $firstErrors = $stderr->await();
            Assert::true(
                $exitCode !== 0,
                self::processReport('foreign-key-validation-sigterm', $firstOutput, $firstErrors),
            );
            self::execute($lockConnection, 'ROLLBACK');
            \pg_close($lockConnection);
            $lockConnection = null;

            $interrupted = $pool->query(<<<SQL
SELECT
    operation.status AS operation_status,
    operation.phase,
    snapshot.status AS snapshot_status,
    snapshot.attempt
FROM gar_import_operations operation
JOIN gar_import_foreign_key_snapshots snapshot ON snapshot.operation_id = operation.id
WHERE operation.id = {$operationId}
SQL)->fetchRow();
            Assert::notNull($interrupted);
            Assert::same($interrupted['operation_status'] ?? null, 'running');
            Assert::same($interrupted['phase'] ?? null, $phase);
            Assert::same($interrupted['snapshot_status'] ?? null, 'validating');
            Assert::same((int) ($interrupted['attempt'] ?? 0), 1);

            $store = new GarInitialLoadStore($storeConnection);
            $store->resumeForeignKeyValidation($operationId);
            [$retry, $retryStdout, $retryStderr] = self::startForeignKeyWorker($dsn, $operationId);
            try {
                $retryExitCode = $retry->join(new TimeoutCancellation(30));
            } finally {
                $retry->kill();
            }
            $retryOutput = $retryStdout->await();
            $retryErrors = $retryStderr->await();
            Assert::same(
                $retryExitCode,
                0,
                self::processReport('foreign-key-validation-resume', $retryOutput, $retryErrors),
            );
            $validated = $pool->query(<<<SQL
SELECT snapshot.status, snapshot.attempt, constraint_row.convalidated
FROM gar_import_foreign_key_snapshots snapshot
JOIN pg_class table_class ON table_class.relname = snapshot.table_name
JOIN pg_namespace namespace
  ON namespace.oid = table_class.relnamespace AND namespace.nspname = 'public'
JOIN pg_constraint constraint_row
  ON constraint_row.conrelid = table_class.oid
 AND constraint_row.conname = snapshot.constraint_name
WHERE snapshot.operation_id = {$operationId}
SQL)->fetchRow();
            Assert::notNull($validated);
            Assert::same($validated['status'] ?? null, 'completed');
            Assert::same((int) ($validated['attempt'] ?? 0), 2);
            Assert::same($validated['convalidated'] ?? null, true);

            if ($overlapIndexForeignKeys) {
                $store->completeIndexBuild($operationId);
                $store->restoreForeignKeys($operationId);
            }
            $store->completeForeignKeyValidation($operationId);
            $completion = $pool->query(<<<SQL
SELECT operation.foreign_key_validation_completed_at = snapshot.completed_at AS uses_snapshot_completion
FROM gar_import_operations operation
JOIN gar_import_foreign_key_snapshots snapshot ON snapshot.operation_id = operation.id
WHERE operation.id = {$operationId}
SQL)->fetchRow();
            Assert::notNull($completion);
            Assert::same($completion['uses_snapshot_completion'] ?? null, true);
            $store->analyze($operationId, [self::VALIDATION_TABLE]);
            $store->complete($operationId, [self::VALIDATION_TABLE]);
            $completed = $pool->query(<<<SQL
SELECT status, phase, foreign_keys_total, foreign_keys_completed,
       (SELECT count(*) FROM gar_import_foreign_key_snapshots WHERE operation_id = {$operationId}) AS backlog
FROM gar_import_operations
WHERE id = {$operationId}
SQL)->fetchRow();
            Assert::notNull($completed);
            Assert::same($completed['status'] ?? null, 'completed');
            Assert::same($completed['phase'] ?? null, 'ready');
            Assert::same((int) ($completed['foreign_keys_total'] ?? 0), 1);
            Assert::same((int) ($completed['foreign_keys_completed'] ?? 0), 1);
            Assert::same((int) ($completed['backlog'] ?? -1), 0);
        } finally {
            if ($process instanceof Process) {
                $process->kill();
            }
            if ($stdout instanceof Future) {
                try {
                    $stdout->await(new TimeoutCancellation(2));
                } catch (Throwable) {
                }
            }
            if ($stderr instanceof Future) {
                try {
                    $stderr->await(new TimeoutCancellation(2));
                } catch (Throwable) {
                }
            }
            if ($lockConnection instanceof Connection) {
                @\pg_query($lockConnection, 'ROLLBACK');
                \pg_close($lockConnection);
            }
            if ($storeConnection instanceof Connection) {
                @\pg_query($storeConnection, 'ROLLBACK');
                \pg_close($storeConnection);
            }
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }

    public function testItSchedulesForeignKeysForTheLargestReferencingTablesFirst(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 3);
        $connection = null;

        try {
            IntegrationDatabase::resetSchema($pool);
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', ['--force-yes' => true]);
            $connection = (new GarImportConnectionFactory($dsn))->connect();
            self::execute($connection, 'CREATE TABLE gar_test_fk_small (value text NOT NULL)');
            self::execute($connection, 'CREATE TABLE gar_test_fk_large (value text NOT NULL)');
            self::execute(
                $connection,
                'INSERT INTO gar_test_fk_large SELECT md5(value::text) FROM generate_series(1, 10000) value',
            );
            $operation = self::row(self::execute(
                $connection,
                <<<'SQL'
INSERT INTO gar_import_operations (
    source_key, source_path, manifest_hash, status, batch_size, mode, phase, write_mode,
    defer_foreign_keys, foreign_keys_total
) VALUES (
    repeat('e', 64), '/data/gar-validation-order', repeat('f', 64), 'running', 50000,
    'initial-load', 'foreign-key-validate', 'direct-copy', true, 2
)
RETURNING id
SQL,
            ));
            $operationId = (int) ($operation['id'] ?? 0);
            Assert::true($operationId > 0);
            self::execute(
                $connection,
                <<<'SQL'
INSERT INTO gar_import_foreign_key_snapshots (
    operation_id, table_name, constraint_name, definition_sql, definition_hash, status
) VALUES
    ($1, 'gar_test_fk_small', 'gar_test_fk_small_fkey', 'FOREIGN KEY (value) REFERENCES target', repeat('0', 64), 'restored'),
    ($1, 'gar_test_fk_large', 'gar_test_fk_large_fkey', 'FOREIGN KEY (value) REFERENCES target', repeat('1', 64), 'restored')
SQL,
                [(string) $operationId],
            );

            $pending = (new GarInitialLoadStore($connection))->pendingForeignKeys($operationId);

            Assert::count($pending, 2);
            $first = $pending[0] ?? null;
            $second = $pending[1] ?? null;
            Assert::notNull($first);
            Assert::notNull($second);
            Assert::same($first->tableName, 'gar_test_fk_large');
            Assert::same($second->tableName, 'gar_test_fk_small');
        } finally {
            if ($connection instanceof Connection) {
                \pg_close($connection);
            }
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }

    private function exerciseRecovery(
        string $fault,
        bool $deferForeignKeys,
        bool $parallelParsers = false,
        bool $fusedRanges = false,
    ): void {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 3);
        $source = self::createSourceFixture();
        $process = null;
        $stdout = null;
        $stderr = null;

        try {
            IntegrationDatabase::resetSchema($pool);
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', ['--force-yes' => true]);
            self::installSlowInsertTrigger($pool);
            [$process, $stdout, $stderr] = self::startImport(
                $dsn,
                $source,
                10,
                $parallelParsers ? 4 : 2,
                2,
                $deferForeignKeys,
                $parallelParsers ? 2 : null,
                $fusedRanges,
            );

            $backendPids = self::awaitPipelineCopyBackends($pool, 1);
            $backendPid = $backendPids[0];
            $writerPids = self::awaitPipelineWriters($process->getPid());
            if ($fault === 'supervisor-sigterm') {
                $process->signal(SIGTERM);
            } elseif ($fault === 'postgres-backend') {
                self::terminateBackend($pool, $backendPid);
            } else {
                throw new RuntimeException("Unknown GAR recovery fault: {$fault}");
            }

            $exitCode = $process->join(new TimeoutCancellation(20));
            $firstOutput = $stdout->await();
            $firstErrors = $stderr->await();
            Assert::true($exitCode !== 0, self::processReport($fault, $firstOutput, $firstErrors));
            self::awaitNoPipelineWriters($writerPids);

            $failed = $pool->query(<<<'SQL'
SELECT id, status, attempt
FROM gar_import_operations
ORDER BY id DESC
LIMIT 1
SQL)->fetchRow();
            Assert::notNull($failed);
            Assert::same($failed['status'] ?? null, 'failed');
            Assert::same((int) ($failed['attempt'] ?? 0), 1);
            $operationId = (int) ($failed['id'] ?? 0);
            Assert::true($operationId > 0);
            if ($deferForeignKeys) {
                $deferredState = $pool->query(<<<SQL
SELECT
    operation.phase,
    operation.defer_foreign_keys,
    operation.foreign_keys_total,
    (SELECT count(*) FROM gar_import_foreign_key_snapshots WHERE operation_id = {$operationId}) AS snapshots,
    (
        SELECT count(*)
        FROM pg_constraint constraint_row
        JOIN pg_class table_class ON table_class.oid = constraint_row.conrelid
        JOIN pg_namespace namespace ON namespace.oid = table_class.relnamespace
        WHERE namespace.nspname = 'public'
          AND table_class.relname IN (
              SELECT DISTINCT table_name
              FROM gar_import_foreign_key_snapshots
              WHERE operation_id = {$operationId}
          )
          AND constraint_row.contype = 'f'
    ) AS installed_foreign_keys
FROM gar_import_operations operation
WHERE operation.id = {$operationId}
SQL)->fetchRow();
                Assert::notNull($deferredState);
                Assert::same($deferredState['phase'] ?? null, 'import');
                Assert::same($deferredState['defer_foreign_keys'] ?? null, true);
                Assert::true((int) ($deferredState['foreign_keys_total'] ?? 0) > 0);
                Assert::same(
                    (int) ($deferredState['snapshots'] ?? 0),
                    (int) ($deferredState['foreign_keys_total'] ?? -1),
                );
                Assert::same((int) ($deferredState['installed_foreign_keys'] ?? -1), 0);
            }

            self::dropSlowInsertTrigger($pool);
            [$resume, $resumeStdout, $resumeStderr] = self::startImport(
                $dsn,
                $source,
                7,
                $parallelParsers ? 6 : 3,
                3,
                $deferForeignKeys,
                $parallelParsers ? 3 : null,
                $fusedRanges,
            );
            try {
                $resumeExitCode = $resume->join(new TimeoutCancellation(90));
            } finally {
                $resume->kill();
            }
            $resumeOutput = $resumeStdout->await();
            $resumeErrors = $resumeStderr->await();
            Assert::same($resumeExitCode, 0, self::processReport($fault, $resumeOutput, $resumeErrors));
            Assert::true(\str_contains($resumeOutput, 'resumed=yes'), $resumeOutput);
            self::assertCompletedState($pool, $operationId, 2, 7, 3, $deferForeignKeys);
        } finally {
            if ($process instanceof Process) {
                $process->kill();
            }
            if ($stdout instanceof Future) {
                try {
                    $stdout->await(new TimeoutCancellation(2));
                } catch (Throwable) {
                }
            }
            if ($stderr instanceof Future) {
                try {
                    $stderr->await(new TimeoutCancellation(2));
                } catch (Throwable) {
                }
            }
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
            self::removeSourceFixture($source);
        }
    }

    /** @return array{Process, Future<string>, Future<string>} */
    private static function startImport(
        string $dsn,
        string $source,
        int $batchSize,
        int $workers,
        int $pipelineWriters,
        bool $deferForeignKeys,
        ?int $pipelineParsers = null,
        bool $fusedRanges = false,
    ): array {
        $root = \dirname(__DIR__, 4);
        $environment = \getenv();
        $environment['APP_NAME'] = 'geo-registry-integration';
        $environment['APP_VERSION'] = 'test';
        $environment['DATABASE_DSN'] = $dsn;
        $arguments = [
            PHP_BINARY,
            $root . '/bin/app',
            'gar:import',
            $source,
            '--batch-size=' . $batchSize,
            '--workers=' . $workers,
            '--initial-load',
            '--direct-copy',
            '--pipeline-file=' . self::PIPELINE_FILE,
            '--pipeline-writers=' . $pipelineWriters,
            '--no-interaction',
            '--no-ansi',
        ];
        if ($pipelineParsers !== null) {
            $arguments[] = '--pipeline-parsers=' . $pipelineParsers;
        }
        if ($fusedRanges) {
            $arguments[] = '--pipeline-fused-ranges';
        }
        if ($deferForeignKeys) {
            $arguments[] = '--defer-foreign-keys';
        }
        $process = Process::start($arguments, $root, $environment);

        return [$process, self::capture($process->getStdout()), self::capture($process->getStderr())];
    }

    /** @return array{Process, Future<string>, Future<string>} */
    private static function startRawImport(
        string $dsn,
        string $source,
        int $batchSize,
        int $primaryWorkers,
        int $childWorkersPerPrimary,
    ): array {
        $root = \dirname(__DIR__, 4);
        $environment = \getenv();
        $environment['APP_NAME'] = 'geo-registry-integration';
        $environment['APP_VERSION'] = 'test';
        $environment['DATABASE_DSN'] = $dsn;
        $process = Process::start([
            PHP_BINARY,
            $root . '/bin/app',
            'gar:import:raw',
            $source,
            '--batch-size=' . $batchSize,
            '--primary-workers=' . $primaryWorkers,
            '--child-workers-per-primary=' . $childWorkersPerPrimary,
            '--pipeline-file=' . self::PIPELINE_FILE,
            '--fragment-mib=8',
            '--defer-foreign-keys',
            '--no-interaction',
            '--no-ansi',
        ], $root, $environment);

        return [$process, self::capture($process->getStdout()), self::capture($process->getStderr())];
    }

    private static function installSlowInsertTrigger(PostgresConnectionPool $pool): void
    {
        $pool->query(<<<'SQL'
CREATE FUNCTION gar_test_slow_object_params() RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    PERFORM pg_sleep(0.02);
    RETURN NEW;
END
$$
SQL);
        $pool->query(<<<'SQL'
CREATE TRIGGER gar_test_slow_object_params
BEFORE INSERT ON object_params
FOR EACH ROW EXECUTE FUNCTION gar_test_slow_object_params()
SQL);
    }

    private static function dropSlowInsertTrigger(PostgresConnectionPool $pool): void
    {
        $pool->query('DROP TRIGGER IF EXISTS gar_test_slow_object_params ON object_params');
        $pool->query('DROP FUNCTION IF EXISTS gar_test_slow_object_params()');
    }

    /** @return array{Process, Future<string>, Future<string>} */
    private static function startForeignKeyWorker(string $dsn, int $operationId): array
    {
        $root = \dirname(__DIR__, 4);
        $environment = \getenv();
        $environment['APP_NAME'] = 'geo-registry-integration';
        $environment['APP_VERSION'] = 'test';
        $environment['DATABASE_DSN'] = $dsn;
        $process = Process::start([
            PHP_BINARY,
            $root . '/bin/app',
            'gar:import:foreign-key-worker',
            (string) $operationId,
            self::VALIDATION_TABLE,
            self::VALIDATION_CONSTRAINT,
            '--no-interaction',
            '--no-ansi',
        ], $root, $environment);

        return [$process, self::capture($process->getStdout()), self::capture($process->getStderr())];
    }

    private static function awaitBlockedForeignKeyValidation(
        PostgresConnectionPool $pool,
        int $operationId,
    ): void {
        $deadline = \microtime(true) + 10;
        do {
            $state = $pool->query(<<<SQL
SELECT
    (SELECT status FROM gar_import_foreign_key_snapshots WHERE operation_id = {$operationId}) AS status,
    EXISTS (
        SELECT 1
        FROM pg_stat_activity
        WHERE datname = current_database()
          AND state = 'active'
          AND wait_event_type = 'Lock'
          AND query ILIKE 'ALTER TABLE public.%VALIDATE CONSTRAINT%'
          AND query ILIKE '%object_params_type_id_fkey%'
    ) AS blocked
SQL)->fetchRow();
            if (($state['status'] ?? null) === 'validating' && ($state['blocked'] ?? null) === true) {
                return;
            }
            delay(0.02);
        } while (\microtime(true) < $deadline);

        throw new RuntimeException('GAR foreign-key validation worker did not block within 10 seconds.');
    }

    private static function foreignKeyDefinition(Connection $connection): string
    {
        $row = self::row(self::execute(
            $connection,
            <<<'SQL'
SELECT pg_get_constraintdef(constraint_row.oid, false) AS definition_sql
FROM pg_constraint constraint_row
JOIN pg_class table_class ON table_class.oid = constraint_row.conrelid
JOIN pg_namespace namespace ON namespace.oid = table_class.relnamespace
WHERE namespace.nspname = 'public'
  AND table_class.relname = $1
  AND constraint_row.conname = $2
  AND constraint_row.contype = 'f'
SQL,
            [self::VALIDATION_TABLE, self::VALIDATION_CONSTRAINT],
        ));
        $definition = $row['definition_sql'] ?? null;
        if (!\is_string($definition) || $definition === '') {
            throw new RuntimeException('Unable to read the GAR validation recovery foreign key definition.');
        }

        return $definition;
    }

    /** @param list<string> $parameters */
    private static function execute(Connection $connection, string $sql, array $parameters = []): \PgSql\Result
    {
        $result = $parameters === []
            ? \pg_query($connection, $sql)
            : \pg_query_params($connection, $sql, $parameters);
        if (!$result instanceof \PgSql\Result) {
            throw new RuntimeException('PostgreSQL recovery test query failed: ' . \pg_last_error($connection));
        }

        return $result;
    }

    /** @return array<array-key, string|null> */
    private static function row(\PgSql\Result $result): array
    {
        $row = \pg_fetch_assoc($result);
        if (!\is_array($row)) {
            throw new RuntimeException('PostgreSQL recovery test query did not return a row.');
        }

        return $row;
    }

    /** @return non-empty-list<int> */
    private static function awaitPipelineCopyBackends(PostgresConnectionPool $pool, int $minimum): array
    {
        $deadline = \microtime(true) + 15;
        do {
            $pids = [];
            foreach ($pool->query(<<<'SQL'
SELECT pid
FROM pg_stat_activity
WHERE datname = current_database()
  AND pid <> pg_backend_pid()
  AND state = 'active'
  AND query ILIKE 'COPY object_params%'
ORDER BY pid
SQL) as $row) {
                $pid = $row['pid'] ?? null;
                if (\is_int($pid) && $pid > 0) {
                    $pids[] = $pid;
                } elseif (\is_string($pid) && \ctype_digit($pid) && (int) $pid > 0) {
                    $pids[] = (int) $pid;
                }
            }
            if ($pids !== [] && \count($pids) >= $minimum) {
                return $pids;
            }
            delay(0.02);
        } while (\microtime(true) < $deadline);

        throw new RuntimeException(
            "GAR pipeline did not start {$minimum} concurrent object_params COPY operations within 15 seconds.",
        );
    }

    /** @return non-empty-list<int> */
    private static function awaitPipelineWriters(int $supervisorPid): array
    {
        $deadline = \microtime(true) + 10;
        do {
            $writers = \array_values(\array_filter(
                self::descendantProcesses($supervisorPid),
                static fn(int $pid): bool => \str_contains(self::processCommand($pid), 'process-runner.php'),
            ));
            if ($writers !== []) {
                return $writers;
            }
            delay(0.02);
        } while (\microtime(true) < $deadline);

        throw new RuntimeException('GAR pipeline batch-writer process was not observed within 10 seconds.');
    }

    /** @return non-empty-list<int> */
    private static function awaitActiveManagedChildWriters(PostgresConnectionPool $pool): array
    {
        $deadline = \microtime(true) + 10;
        do {
            $writers = [];
            foreach ($pool->query(<<<'SQL'
SELECT application_name
FROM pg_stat_activity
WHERE datname = current_database()
  AND state = 'active'
  AND query ILIKE 'COPY object_params%'
  AND application_name LIKE 'geo-registry-gar-raw-child-%'
ORDER BY application_name
SQL) as $row) {
                $applicationName = $row['application_name'] ?? null;
                if (!\is_string($applicationName)) {
                    continue;
                }
                $processId = \substr($applicationName, \strlen('geo-registry-gar-raw-child-'));
                if (\ctype_digit($processId) && (int) $processId > 0) {
                    $writers[] = (int) $processId;
                }
            }
            if ($writers !== []) {
                return $writers;
            }
            delay(0.02);
        } while (\microtime(true) < $deadline);

        throw new RuntimeException('GAR managed child process was not observed within 10 seconds.');
    }

    /** @param list<int> $writerPids */
    private static function awaitNoPipelineWriters(array $writerPids): void
    {
        $deadline = \microtime(true) + 10;
        do {
            $running = \array_filter(
                $writerPids,
                static fn(int $pid): bool => \str_contains(self::processCommand($pid), 'process-runner.php'),
            );
            if ($running === []) {
                return;
            }
            delay(0.05);
        } while (\microtime(true) < $deadline);

        throw new RuntimeException('GAR pipeline left a batch-writer process after supervisor exit.');
    }

    private static function terminateBackend(PostgresConnectionPool $pool, int $pid): void
    {
        $row = $pool->query("SELECT pg_terminate_backend({$pid}) AS terminated")->fetchRow();
        if (($row['terminated'] ?? null) !== true) {
            throw new RuntimeException("Unable to terminate GAR pipeline PostgreSQL backend {$pid}.");
        }
    }

    private static function killProcess(int $pid): void
    {
        if ($pid < 1 || !\posix_kill($pid, SIGKILL)) {
            throw new RuntimeException("Unable to kill GAR pipeline writer process {$pid}.");
        }
    }

    private static function assertCompletedState(
        PostgresConnectionPool $pool,
        int $operationId,
        int $attempt,
        int $batchSize,
        int $pipelineWriters,
        bool $deferForeignKeys,
    ): void {
        $state = $pool->query(<<<'SQL'
SELECT
    operation.id,
    operation.status,
    operation.attempt,
    operation.batch_size,
    operation.pipeline_writers,
    operation.defer_foreign_keys,
    operation.foreign_keys_total,
    operation.foreign_keys_completed,
    operation.files_total,
    operation.files_completed,
    operation.rows_processed,
    operation.rows_merged,
    (SELECT count(*) FROM gar_import_operations) AS operations,
    (SELECT count(*) FROM gar_import_files WHERE status <> 'completed') AS incomplete_files,
    (SELECT count(*) FROM gar_import_file_batches) AS pending_ranges,
    (SELECT count(*) FROM gar_import_index_snapshots) AS index_backlog,
    (SELECT count(*) FROM gar_import_foreign_key_snapshots) AS foreign_key_backlog,
    (
        SELECT count(*)
        FROM pg_constraint constraint_row
        JOIN pg_class table_class ON table_class.oid = constraint_row.conrelid
        JOIN pg_namespace namespace ON namespace.oid = table_class.relnamespace
        WHERE namespace.nspname = 'public'
          AND table_class.relname IN (
              'address_object_divisions', 'address_objects', 'administrative_hierarchy',
              'change_history', 'houses', 'municipal_hierarchy', 'normative_docs',
              'object_params', 'reestr_objects', 'rooms', 'stead'
          )
          AND constraint_row.contype = 'f'
          AND NOT constraint_row.convalidated
    ) AS unvalidated_foreign_keys,
    (SELECT count(*) FROM object_params) AS object_params,
    (SELECT count(DISTINCT id) FROM object_params) AS distinct_object_params,
    (SELECT md5(string_agg(id::text || ':' || value, ',' ORDER BY id)) FROM object_params) AS object_params_digest
FROM gar_import_operations operation
ORDER BY operation.id DESC
LIMIT 1
SQL)->fetchRow();
        Assert::notNull($state);
        Assert::same((int) ($state['id'] ?? 0), $operationId);
        Assert::same($state['status'] ?? null, 'completed');
        Assert::same((int) ($state['attempt'] ?? 0), $attempt);
        Assert::same((int) ($state['batch_size'] ?? 0), $batchSize);
        Assert::same((int) ($state['pipeline_writers'] ?? 0), $pipelineWriters);
        Assert::same($state['defer_foreign_keys'] ?? null, $deferForeignKeys);
        Assert::same((int) ($state['operations'] ?? 0), 1);
        Assert::same((int) ($state['files_total'] ?? 0), 5);
        Assert::same((int) ($state['files_completed'] ?? 0), 5);
        Assert::same((int) ($state['rows_processed'] ?? 0), self::TARGET_ROWS + 4);
        Assert::same((int) ($state['rows_merged'] ?? 0), self::TARGET_ROWS + 4);
        Assert::same((int) ($state['incomplete_files'] ?? -1), 0);
        Assert::same((int) ($state['pending_ranges'] ?? -1), 0);
        Assert::same((int) ($state['index_backlog'] ?? -1), 0);
        Assert::same((int) ($state['foreign_key_backlog'] ?? -1), 0);
        Assert::same((int) ($state['unvalidated_foreign_keys'] ?? -1), 0);
        if ($deferForeignKeys) {
            Assert::true((int) ($state['foreign_keys_total'] ?? 0) > 0);
            Assert::same(
                (int) ($state['foreign_keys_completed'] ?? 0),
                (int) ($state['foreign_keys_total'] ?? -1),
            );
        } else {
            Assert::same((int) ($state['foreign_keys_total'] ?? -1), 0);
            Assert::same((int) ($state['foreign_keys_completed'] ?? -1), 0);
        }
        Assert::same((int) ($state['object_params'] ?? 0), self::TARGET_ROWS + 1);
        Assert::same((int) ($state['distinct_object_params'] ?? 0), self::TARGET_ROWS + 1);
        Assert::same($state['object_params_digest'] ?? null, self::expectedDigest());
    }

    private static function expectedDigest(): string
    {
        $rows = ['770010:sibling'];
        for ($row = 1; $row <= self::TARGET_ROWS; ++$row) {
            $rows[] = (880_000 + $row) . ':recovery-' . $row;
        }

        return \md5(\implode(',', $rows));
    }

    private static function createSourceFixture(): string
    {
        $source = \sys_get_temp_dir() . '/geo-registry-gar-recovery-' . \bin2hex(\random_bytes(8));
        if (!\mkdir($source, 0700)) {
            throw new RuntimeException("Unable to create GAR recovery fixture {$source}.");
        }
        $fixture = \dirname(__DIR__, 3) . '/Fixture/Gar/pipeline';
        $files = \scandir($fixture);
        if (!\is_array($files)) {
            throw new RuntimeException('Unable to enumerate the GAR pipeline fixture.');
        }
        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || $file === self::PIPELINE_FILE) {
                continue;
            }
            if (!\copy($fixture . '/' . $file, $source . '/' . $file)) {
                throw new RuntimeException("Unable to copy GAR recovery fixture file {$file}.");
            }
        }

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<PARAMS>\n";
        for ($row = 1; $row <= self::TARGET_ROWS; ++$row) {
            $id = 880_000 + $row;
            $xml .= \sprintf(
                '  <PARAM ID="%d" OBJECTID="770001" CHANGEID="0" CHANGEIDEND="0" TYPEID="1" '
                    . 'VALUE="recovery-%d" UPDATEDATE="2026-11-04" STARTDATE="2026-01-01" '
                    . "ENDDATE=\"2079-06-06\"/>\n",
                $id,
                $row,
            );
        }
        $xml .= "</PARAMS>\n";
        if (\file_put_contents($source . '/' . self::PIPELINE_FILE, $xml) === false) {
            throw new RuntimeException('Unable to write the GAR recovery pipeline XML fixture.');
        }

        return $source;
    }

    private static function removeSourceFixture(string $source): void
    {
        $files = \scandir($source);
        if (\is_array($files)) {
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..') {
                    @\unlink($source . '/' . $file);
                }
            }
        }
        @\rmdir($source);
    }

    /** @return list<int> */
    private static function descendantProcesses(int $parentPid): array
    {
        $descendants = [];
        $pending = [$parentPid];
        while ($pending !== []) {
            $parent = \array_shift($pending);
            foreach (self::childProcesses($parent) as $child) {
                $descendants[] = $child;
                $pending[] = $child;
            }
        }

        return $descendants;
    }

    /** @return list<int> */
    private static function childProcesses(int $parentPid): array
    {
        $children = @\file_get_contents(\sprintf('/proc/%d/task/%d/children', $parentPid, $parentPid));
        if (!\is_string($children) || \trim($children) === '') {
            return [];
        }
        $parts = \preg_split('/\s+/', \trim($children));

        return \is_array($parts)
            ? \array_values(\array_filter(\array_map('intval', $parts), static fn(int $pid): bool => $pid > 0))
            : [];
    }

    private static function processCommand(int $pid): string
    {
        $command = @\file_get_contents("/proc/{$pid}/cmdline");

        return \is_string($command) ? \str_replace("\0", ' ', $command) : '';
    }

    /** @return Future<string> */
    private static function capture(ReadableStream $stream): Future
    {
        return async(static fn(): string => buffer($stream));
    }

    private static function processReport(string $fault, string $stdout, string $stderr): string
    {
        return "Fault: {$fault}\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}";
    }
}
