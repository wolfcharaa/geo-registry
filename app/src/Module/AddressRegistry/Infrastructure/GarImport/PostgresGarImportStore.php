<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use App\Module\AddressRegistry\Capability\ImportGar\ImportGarResult;
use PgSql\Connection;
use PgSql\Result;
use Throwable;

final readonly class PostgresGarImportStore
{
    private const STAGE_TABLE = 'gar_import_stage';

    public function __construct(
        private Connection $connection,
    ) {}

    public function acquireLock(string $sourceKey): void
    {
        $row = $this->row($this->queryParams(
            'SELECT pg_try_advisory_lock(20261110, hashtext($1)) AS locked',
            [$sourceKey],
        ));
        if (($row['locked'] ?? null) !== 't') {
            throw new GarImportFailed('Another GAR import is already running for this source.');
        }
    }

    public function acquireDatabaseLock(bool $initialLoad): void
    {
        $function = $initialLoad ? 'pg_try_advisory_lock' : 'pg_try_advisory_lock_shared';
        $row = $this->row($this->query(
            "SELECT {$function}(20261110, 0) AS locked",
        ));
        if (($row['locked'] ?? null) !== 't') {
            throw new GarImportFailed('Another GAR import is using this database.');
        }
    }

    public function releaseDatabaseLock(bool $initialLoad): void
    {
        $function = $initialLoad ? 'pg_advisory_unlock' : 'pg_advisory_unlock_shared';
        $this->query("SELECT {$function}(20261110, 0)");
    }

    public function ensureNoInitialLoadRecoveryRequired(bool $initialLoad): void
    {
        if ($initialLoad) {
            return;
        }
        $row = $this->row($this->query(<<<'SQL'
SELECT
    (SELECT count(*) FROM gar_import_index_snapshots) AS index_snapshots,
    (SELECT count(*) FROM gar_import_foreign_key_snapshots) AS foreign_key_snapshots
SQL));
        if (
            self::integer($row, 'index_snapshots') !== 0
            || self::integer($row, 'foreign_key_snapshots') !== 0
        ) {
            throw new GarImportFailed(
                'GAR initial-load index or foreign-key recovery is incomplete; resume the matching operation.',
            );
        }
    }

    public function releaseLock(string $sourceKey): void
    {
        $this->queryParams('SELECT pg_advisory_unlock(20261110, hashtext($1))', [$sourceKey]);
    }

    /**
     * @param list<string> $pipelineFileKeys
     * @param list<GarXmlSourceFile> $files
     */
    public function startOperation(
        string $sourceKey,
        string $sourcePath,
        string $manifestHash,
        int $batchSize,
        bool $restart,
        bool $initialLoad,
        bool $directCopy,
        ?string $pipelineFileKey,
        ?int $pipelineWriters,
        bool $deferPrimaryKeys,
        bool $deferForeignKeys,
        array $pipelineFileKeys,
        array $files,
    ): GarImportOperationState {
        $pipelineFileKeySet = \array_fill_keys($pipelineFileKeys, true);
        if (($pipelineFileKey === null) !== ($pipelineFileKeys === [])) {
            throw new GarImportFailed('GAR pipeline identity does not match its selected XML files.');
        }
        if (\count($pipelineFileKeySet) !== \count($pipelineFileKeys)) {
            throw new GarImportFailed('GAR pipeline XML selection contains duplicate file keys.');
        }
        $manifestFileKeySet = \array_fill_keys(\array_map(
            static fn(GarXmlSourceFile $file): string => $file->key,
            $files,
        ), true);
        foreach ($pipelineFileKeys as $selectedFileKey) {
            if (!isset($manifestFileKeySet[$selectedFileKey])) {
                throw new GarImportFailed('GAR pipeline XML selection is outside the import manifest.');
            }
        }
        $this->query('BEGIN');

        try {
            $running = $this->optionalRow($this->queryParams(
                <<<'SQL'
SELECT id, manifest_hash, mode, write_mode, pipeline_file_key,
       defer_primary_keys, defer_foreign_keys
FROM gar_import_operations
WHERE source_key = $1 AND status = 'running'
ORDER BY id DESC
LIMIT 1
FOR UPDATE
SQL,
                [$sourceKey],
            ));

            if ($restart) {
                $this->queryParams(
                    <<<'SQL'
UPDATE gar_import_operations
SET status = 'abandoned', updated_at = now(), failed_at = now(),
    error_message = 'Operation explicitly restarted by CLI operator.'
WHERE source_key = $1 AND status IN ('running', 'failed')
SQL,
                    [$sourceKey],
                );
            } elseif ($running !== null) {
                if (self::string($running, 'mode') !== self::mode($initialLoad)) {
                    throw new GarImportFailed('Another GAR import mode is already running for this source.');
                }
                if (self::string($running, 'write_mode') !== self::writeMode($directCopy)) {
                    throw new GarImportFailed('Another GAR import write mode is already running for this source.');
                }
                if (self::nullableString($running, 'pipeline_file_key') !== $pipelineFileKey) {
                    throw new GarImportFailed('Another GAR import pipeline target is already running for this source.');
                }
                if (self::boolean($running, 'defer_foreign_keys') !== $deferForeignKeys) {
                    throw new GarImportFailed(
                        'Another GAR import foreign-key lifecycle is already running for this source.',
                    );
                }
                if (self::boolean($running, 'defer_primary_keys') !== $deferPrimaryKeys) {
                    throw new GarImportFailed(
                        'Another GAR import primary-key lifecycle is already running for this source.',
                    );
                }
                if (\trim(self::string($running, 'manifest_hash')) !== $manifestHash) {
                    throw new GarImportFailed(
                        'GAR source changed after an interrupted operation; use --restart to discard its checkpoint.',
                    );
                }

                $operationId = self::integer($running, 'id');
                $this->assertPipelineFiles($operationId, $pipelineFileKeys);
                $this->resumeOperation($operationId, $batchSize, $pipelineWriters);
                $this->query('COMMIT');

                return new GarImportOperationState($operationId, true, false);
            }

            if (!$restart) {
                $existing = $this->optionalRow($this->queryParams(
                    <<<'SQL'
SELECT id, status
FROM gar_import_operations
WHERE source_key = $1 AND manifest_hash = $2 AND mode = $3 AND write_mode = $4
  AND pipeline_file_key IS NOT DISTINCT FROM $5
  AND defer_primary_keys = $6
  AND defer_foreign_keys = $7
  AND status IN ('failed', 'completed')
ORDER BY id DESC
LIMIT 1
FOR UPDATE
SQL,
                    [
                        $sourceKey,
                        $manifestHash,
                        self::mode($initialLoad),
                        self::writeMode($directCopy),
                        $pipelineFileKey,
                        $deferPrimaryKeys ? 't' : 'f',
                        $deferForeignKeys ? 't' : 'f',
                    ],
                ));
                if ($existing !== null) {
                    $operationId = self::integer($existing, 'id');
                    $this->assertPipelineFiles($operationId, $pipelineFileKeys);
                    if (self::string($existing, 'status') === 'completed') {
                        $this->query('COMMIT');

                        return new GarImportOperationState($operationId, false, true);
                    }

                    $this->resumeOperation($operationId, $batchSize, $pipelineWriters);
                    $this->query('COMMIT');

                    return new GarImportOperationState($operationId, true, false);
                }
            }

            $operation = $this->row($this->queryParams(
                <<<'SQL'
INSERT INTO gar_import_operations (
    source_key, source_path, manifest_hash, status, batch_size, files_total, mode, phase, write_mode,
    pipeline_file_key, pipeline_writers, defer_primary_keys, defer_foreign_keys
) VALUES ($1, $2, $3, 'running', $4, $5, $6, $7, $8, $9, $10, $11, $12)
RETURNING id
SQL,
                [
                    $sourceKey,
                    $sourcePath,
                    $manifestHash,
                    (string) $batchSize,
                    (string) \count($files),
                    self::mode($initialLoad),
                    $initialLoad ? 'prepare' : 'import',
                    self::writeMode($directCopy),
                    $pipelineFileKey,
                    $pipelineWriters === null ? null : (string) $pipelineWriters,
                    $deferPrimaryKeys ? 't' : 'f',
                    $deferForeignKeys ? 't' : 'f',
                ],
            ));
            $operationId = self::integer($operation, 'id');

            foreach ($files as $file) {
                $this->queryParams(
                    <<<'SQL'
INSERT INTO gar_import_files (
    operation_id, file_key, relative_path, descriptor_key, table_name, file_size, file_mtime,
    pipeline_enabled
) VALUES ($1, $2, $3, $4, $5, $6, $7, $8)
SQL,
                    [
                        (string) $operationId,
                        $file->key,
                        $file->relativePath,
                        $file->descriptor->key,
                        $file->descriptor->table,
                        (string) $file->size,
                        (string) $file->modifiedAt,
                        isset($pipelineFileKeySet[$file->key]) ? 't' : 'f',
                    ],
                );
            }

            $this->query('COMMIT');

            return new GarImportOperationState($operationId, false, false);
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function fileState(int $operationId, string $fileKey): GarImportFileState
    {
        $row = $this->row($this->queryParams(
            'SELECT status, processed_rows FROM gar_import_files WHERE operation_id = $1 AND file_key = $2',
            [(string) $operationId, $fileKey],
        ));

        return new GarImportFileState(
            processedRows: self::integer($row, 'processed_rows'),
            completed: self::string($row, 'status') === 'completed',
        );
    }

    public function startFile(int $operationId, string $fileKey): void
    {
        $this->queryParams(
            <<<'SQL'
UPDATE gar_import_files
SET status = 'running', started_at = COALESCE(started_at, now()), updated_at = now(),
    failed_at = NULL, error_message = NULL
WHERE operation_id = $1 AND file_key = $2 AND status <> 'completed'
SQL,
            [(string) $operationId, $fileKey],
        );
    }

    public function prepareStage(GarXmlSourceFile $file): void
    {
        $columns = \implode(', ', $file->descriptor->stageColumns());
        $this->query('DROP TABLE IF EXISTS ' . self::STAGE_TABLE);
        $this->query(\sprintf(
            'CREATE TEMP TABLE %s ON COMMIT DELETE ROWS AS SELECT %s FROM %s WITH NO DATA',
            self::STAGE_TABLE,
            $columns,
            $file->descriptor->table,
        ));
    }

    public function assertWorkerWriteMode(int $operationId, bool $directCopy): void
    {
        $operation = $this->row($this->queryParams(
            <<<'SQL'
SELECT status, mode, write_mode
FROM gar_import_operations
WHERE id = $1
SQL,
            [(string) $operationId],
        ));
        $expected = self::writeMode($directCopy);
        if (
            self::string($operation, 'status') !== 'running'
            || self::string($operation, 'write_mode') !== $expected
            || ($directCopy && self::string($operation, 'mode') !== 'initial-load')
        ) {
            throw new GarImportFailed('GAR import worker write mode does not match its running operation.');
        }
    }

    /** @return list<GarImportCommittedRange> */
    public function committedRanges(int $operationId, string $fileKey): array
    {
        $result = $this->queryParams(
            <<<'SQL'
SELECT first_row, last_row
FROM gar_import_file_batches
WHERE operation_id = $1 AND file_key = $2
ORDER BY first_row
SQL,
            [(string) $operationId, $fileKey],
        );
        $ranges = [];
        while (($row = $this->optionalRow($result)) !== null) {
            $ranges[] = new GarImportCommittedRange(
                self::integer($row, 'first_row'),
                self::integer($row, 'last_row'),
            );
        }

        return $ranges;
    }

    /**
     * @param non-empty-list<string> $rows
     */
    public function commitDirectCopyRange(
        int $operationId,
        string $fileKey,
        string $copyTarget,
        array $rows,
        int $firstRow,
        int $lastRow,
        int $encodedBytes,
    ): int {
        $rowCount = \count($rows);
        if (
            $firstRow < 1
            || $lastRow < $firstRow
            || $lastRow - $firstRow + 1 !== $rowCount
            || $encodedBytes < 1
        ) {
            throw new GarImportFailed('GAR pipeline batch must describe one encoded contiguous row range.');
        }

        $this->query('BEGIN');
        try {
            if (!\pg_copy_from($this->connection, $copyTarget, $rows)) {
                throw new GarImportFailed('PostgreSQL COPY failed: ' . \pg_last_error($this->connection));
            }
            $processedRows = new GarDirectCopyRangeLedger($this->connection)->record(
                operationId: $operationId,
                fileKey: $fileKey,
                firstRow: $firstRow,
                lastRow: $lastRow,
                rowCount: $rowCount,
                encodedBytes: $encodedBytes,
            );
            $this->query('COMMIT');

            return $processedRows;
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function recordPipelinePeak(int $operationId, int $batches, int $bytes): void
    {
        if ($batches < 0 || $bytes < 0) {
            throw new GarImportFailed('GAR pipeline in-flight metrics must not be negative.');
        }
        $this->queryParams(
            <<<'SQL'
UPDATE gar_import_operations
SET pipeline_peak_in_flight_batches = GREATEST(pipeline_peak_in_flight_batches, $2),
    pipeline_peak_in_flight_bytes = GREATEST(pipeline_peak_in_flight_bytes, $3),
    updated_at = now()
WHERE id = $1 AND status = 'running'
SQL,
            [(string) $operationId, (string) $batches, (string) $bytes],
        );
    }

    public function completePipelineFile(int $operationId, string $fileKey): void
    {
        $this->query('BEGIN');
        try {
            $file = $this->row($this->queryParams(
                <<<'SQL'
SELECT status, processed_rows, merged_rows,
       pipeline_enabled,
       (
           SELECT count(*)
           FROM gar_import_file_batches batch
           WHERE batch.operation_id = gar_import_files.operation_id
             AND batch.file_key = gar_import_files.file_key
       ) AS pending_ranges
FROM gar_import_files
WHERE operation_id = $1 AND file_key = $2
FOR UPDATE
SQL,
                [(string) $operationId, $fileKey],
            ));
            if (
                self::string($file, 'status') === 'completed'
                || !self::boolean($file, 'pipeline_enabled')
                || self::integer($file, 'processed_rows') !== self::integer($file, 'merged_rows')
                || self::integer($file, 'pending_ranges') !== 0
            ) {
                throw new GarImportFailed(
                    'GAR pipeline file cannot complete before every committed range is contiguous.',
                );
            }
            $this->queryParams(
                <<<'SQL'
UPDATE gar_import_files
SET status = 'completed', completed_at = now(), updated_at = now(),
    failed_at = NULL, error_message = NULL
WHERE operation_id = $1 AND file_key = $2
SQL,
                [(string) $operationId, $fileKey],
            );
            $this->queryParams(
                'UPDATE gar_import_operations SET files_completed = files_completed + 1, updated_at = now() WHERE id = $1',
                [(string) $operationId],
            );
            $this->query('COMMIT');
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    /** @param list<string> $rows */
    public function mergeBatch(
        int $operationId,
        GarXmlSourceFile $file,
        array $rows,
        int $processedRows,
        bool $directCopy = false,
    ): void {
        if ($rows === []) {
            return;
        }

        $this->query('BEGIN');

        try {
            $copyTarget = $directCopy ? $file->descriptor->directCopyTarget() : self::STAGE_TABLE;
            if (!\pg_copy_from($this->connection, $copyTarget, $rows)) {
                throw new GarImportFailed('PostgreSQL COPY failed: ' . \pg_last_error($this->connection));
            }
            $mergedRows = $directCopy
                ? \count($rows)
                : \pg_affected_rows($this->query($file->descriptor->mergeSql(self::STAGE_TABLE)));
            $this->queryParams(
                <<<'SQL'
UPDATE gar_import_files
SET processed_rows = $3, merged_rows = merged_rows + $4, updated_at = now()
WHERE operation_id = $1 AND file_key = $2
SQL,
                [
                    (string) $operationId,
                    $file->key,
                    (string) $processedRows,
                    (string) $mergedRows,
                ],
            );
            $this->queryParams(
                <<<'SQL'
UPDATE gar_import_operations
SET rows_processed = rows_processed + $2, rows_merged = rows_merged + $3, updated_at = now()
WHERE id = $1
SQL,
                [(string) $operationId, (string) \count($rows), (string) $mergedRows],
            );
            $this->query('COMMIT');
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function completeFile(int $operationId, string $fileKey): void
    {
        $this->query('BEGIN');

        try {
            $updated = $this->queryParams(
                <<<'SQL'
UPDATE gar_import_files
SET status = 'completed', completed_at = now(), updated_at = now(), failed_at = NULL, error_message = NULL
WHERE operation_id = $1 AND file_key = $2 AND status <> 'completed'
SQL,
                [(string) $operationId, $fileKey],
            );
            if (\pg_affected_rows($updated) === 1) {
                $this->queryParams(
                    'UPDATE gar_import_operations SET files_completed = files_completed + 1, updated_at = now() WHERE id = $1',
                    [(string) $operationId],
                );
            }
            $this->query('COMMIT');
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function completeOperation(int $operationId): void
    {
        $updated = $this->queryParams(
            <<<'SQL'
UPDATE gar_import_operations
SET status = 'completed', phase = 'ready', completed_at = now(), updated_at = now(),
    failed_at = NULL, error_message = NULL
WHERE id = $1 AND files_completed = files_total
SQL,
            [(string) $operationId],
        );
        if (\pg_affected_rows($updated) !== 1) {
            throw new GarImportFailed('GAR import cannot be completed before every catalog file is complete.');
        }
    }

    public function failOperation(int $operationId, ?string $fileKey, Throwable $error): void
    {
        $message = \substr($error->getMessage(), 0, 2000);

        try {
            if ($fileKey !== null) {
                $this->queryParams(
                    <<<'SQL'
UPDATE gar_import_files
SET status = 'failed', failed_at = now(), updated_at = now(), error_message = $3
WHERE operation_id = $1 AND file_key = $2 AND status <> 'completed'
SQL,
                    [(string) $operationId, $fileKey, $message],
                );
            }
            $this->queryParams(
                <<<'SQL'
UPDATE gar_import_operations
SET status = 'failed', failed_at = now(), updated_at = now(), error_message = $2
WHERE id = $1 AND status <> 'completed'
SQL,
                [(string) $operationId, $message],
            );
        } catch (Throwable) {
            // Preserve the original import failure when the connection is already lost.
        }
    }

    public function failFile(int $operationId, string $fileKey, Throwable $error): void
    {
        try {
            $this->queryParams(
                <<<'SQL'
UPDATE gar_import_files
SET status = 'failed', failed_at = now(), updated_at = now(), error_message = $3
WHERE operation_id = $1 AND file_key = $2 AND status <> 'completed'
SQL,
                [(string) $operationId, $fileKey, \substr($error->getMessage(), 0, 2000)],
            );
        } catch (Throwable) {
            // Preserve the worker failure when the connection is already lost.
        }
    }

    public function result(int $operationId, bool $resumed, bool $alreadyCompleted): ImportGarResult
    {
        $row = $this->row($this->queryParams(
            <<<'SQL'
SELECT files_total, files_completed, rows_processed, rows_merged,
       mode, phase, write_mode, indexes_total, indexes_completed,
       pipeline_file_key, pipeline_writers, pipeline_batches, pipeline_bytes,
       pipeline_peak_in_flight_batches, pipeline_peak_in_flight_bytes,
       defer_primary_keys, defer_foreign_keys, foreign_keys_total, foreign_keys_completed,
       pipeline.pipeline_file
FROM gar_import_operations
LEFT JOIN LATERAL (
    SELECT CASE
        WHEN count(*) = 0 THEN NULL
        WHEN count(*) = 1 THEN min(file.relative_path)
        ELSE 'auto:' || count(*)::text
    END AS pipeline_file
    FROM gar_import_files file
    WHERE file.operation_id = gar_import_operations.id
      AND file.pipeline_enabled
) pipeline ON true
WHERE id = $1
SQL,
            [(string) $operationId],
        ));

        return new ImportGarResult(
            operationId: $operationId,
            filesTotal: self::integer($row, 'files_total'),
            filesCompleted: self::integer($row, 'files_completed'),
            rowsProcessed: self::integer($row, 'rows_processed'),
            rowsMerged: self::integer($row, 'rows_merged'),
            resumed: $resumed,
            alreadyCompleted: $alreadyCompleted,
            initialLoad: self::string($row, 'mode') === 'initial-load',
            phase: self::string($row, 'phase'),
            indexesTotal: self::integer($row, 'indexes_total'),
            indexesCompleted: self::integer($row, 'indexes_completed'),
            directCopy: self::string($row, 'write_mode') === 'direct-copy',
            pipelineFile: self::nullableString($row, 'pipeline_file'),
            pipelineWriters: self::nullableInteger($row, 'pipeline_writers'),
            pipelineBatches: self::integer($row, 'pipeline_batches'),
            pipelineBytes: self::integer($row, 'pipeline_bytes'),
            pipelinePeakInFlightBatches: self::integer($row, 'pipeline_peak_in_flight_batches'),
            pipelinePeakInFlightBytes: self::integer($row, 'pipeline_peak_in_flight_bytes'),
            deferPrimaryKeys: self::boolean($row, 'defer_primary_keys'),
            deferForeignKeys: self::boolean($row, 'defer_foreign_keys'),
            foreignKeysTotal: self::integer($row, 'foreign_keys_total'),
            foreignKeysCompleted: self::integer($row, 'foreign_keys_completed'),
        );
    }

    private function resumeOperation(int $operationId, int $batchSize, ?int $pipelineWriters): void
    {
        $this->queryParams(
            <<<'SQL'
UPDATE gar_import_operations
SET status = 'running', batch_size = $2, pipeline_writers = $3,
    attempt = attempt + 1, updated_at = now(),
    completed_at = NULL, failed_at = NULL, error_message = NULL
WHERE id = $1
SQL,
            [
                (string) $operationId,
                (string) $batchSize,
                $pipelineWriters === null ? null : (string) $pipelineWriters,
            ],
        );
    }

    /** @param list<string> $expectedFileKeys */
    private function assertPipelineFiles(int $operationId, array $expectedFileKeys): void
    {
        $result = $this->queryParams(
            <<<'SQL'
SELECT file_key
FROM gar_import_files
WHERE operation_id = $1 AND pipeline_enabled
ORDER BY file_key
SQL,
            [(string) $operationId],
        );
        $actualFileKeys = [];
        while (($row = $this->optionalRow($result)) !== null) {
            $actualFileKeys[] = \trim(self::string($row, 'file_key'));
        }
        \sort($expectedFileKeys);
        if ($actualFileKeys !== $expectedFileKeys) {
            throw new GarImportFailed(
                'GAR pipeline XML selection changed after an interrupted operation; use the original selection.',
            );
        }
    }

    private function query(string $sql): Result
    {
        $result = \pg_query($this->connection, $sql);
        if (!$result instanceof Result) {
            throw new GarImportFailed('PostgreSQL query failed: ' . \pg_last_error($this->connection));
        }

        return $result;
    }

    /** @param list<?string> $parameters */
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
            ?? throw new GarImportFailed('PostgreSQL operation state row was not found.');
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
    private static function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (!\is_string($value) || !\ctype_digit($value)) {
            throw new GarImportFailed("PostgreSQL returned an invalid integer field: {$key}");
        }

        return (int) $value;
    }

    /** @param array<string, string|null> $row */
    private static function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!\is_string($value)) {
            throw new GarImportFailed("PostgreSQL returned an invalid string field: {$key}");
        }

        return $value;
    }

    /** @param array<string, string|null> $row */
    private static function boolean(array $row, string $key): bool
    {
        return match ($row[$key] ?? null) {
            't' => true,
            'f' => false,
            default => throw new GarImportFailed("PostgreSQL returned an invalid boolean field: {$key}"),
        };
    }

    /** @param array<string, string|null> $row */
    private static function nullableString(array $row, string $key): ?string
    {
        return $row[$key] ?? null;
    }

    /** @param array<string, string|null> $row */
    private static function nullableInteger(array $row, string $key): ?int
    {
        $value = self::nullableString($row, $key);
        if ($value === null) {
            return null;
        }
        if (!\ctype_digit($value)) {
            throw new GarImportFailed("PostgreSQL returned an invalid optional integer field: {$key}");
        }

        return (int) $value;
    }

    private static function mode(bool $initialLoad): string
    {
        return $initialLoad ? 'initial-load' : 'standard';
    }

    private static function writeMode(bool $directCopy): string
    {
        return $directCopy ? 'direct-copy' : 'merge';
    }

    private function rollback(): void
    {
        \pg_query($this->connection, 'ROLLBACK');
    }
}
