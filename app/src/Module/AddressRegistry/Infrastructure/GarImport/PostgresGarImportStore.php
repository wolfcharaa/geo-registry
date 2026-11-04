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
            'SELECT pg_try_advisory_lock(hashtext($1)) AS locked',
            [$sourceKey],
        ));
        if (($row['locked'] ?? null) !== 't') {
            throw new GarImportFailed('Another GAR import is already running for this source.');
        }
    }

    public function releaseLock(string $sourceKey): void
    {
        $this->queryParams('SELECT pg_advisory_unlock(hashtext($1))', [$sourceKey]);
    }

    /** @param list<GarXmlSourceFile> $files */
    public function startOperation(
        string $sourceKey,
        string $sourcePath,
        string $manifestHash,
        int $batchSize,
        bool $restart,
        array $files,
    ): GarImportOperationState {
        $this->query('BEGIN');

        try {
            $running = $this->optionalRow($this->queryParams(
                <<<'SQL'
SELECT id, manifest_hash
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
                if (trim(self::string($running, 'manifest_hash')) !== $manifestHash) {
                    throw new GarImportFailed(
                        'GAR source changed after an interrupted operation; use --restart to discard its checkpoint.',
                    );
                }

                $operationId = self::integer($running, 'id');
                $this->resumeOperation($operationId, $batchSize);
                $this->query('COMMIT');

                return new GarImportOperationState($operationId, true, false);
            }

            if (!$restart) {
                $existing = $this->optionalRow($this->queryParams(
                    <<<'SQL'
SELECT id, status
FROM gar_import_operations
WHERE source_key = $1 AND manifest_hash = $2 AND status IN ('failed', 'completed')
ORDER BY id DESC
LIMIT 1
FOR UPDATE
SQL,
                    [$sourceKey, $manifestHash],
                ));
                if ($existing !== null) {
                    $operationId = self::integer($existing, 'id');
                    if (self::string($existing, 'status') === 'completed') {
                        $this->query('COMMIT');

                        return new GarImportOperationState($operationId, false, true);
                    }

                    $this->resumeOperation($operationId, $batchSize);
                    $this->query('COMMIT');

                    return new GarImportOperationState($operationId, true, false);
                }
            }

            $operation = $this->row($this->queryParams(
                <<<'SQL'
INSERT INTO gar_import_operations (
    source_key, source_path, manifest_hash, status, batch_size, files_total
) VALUES ($1, $2, $3, 'running', $4, $5)
RETURNING id
SQL,
                [$sourceKey, $sourcePath, $manifestHash, (string) $batchSize, (string) count($files)],
            ));
            $operationId = self::integer($operation, 'id');

            foreach ($files as $file) {
                $this->queryParams(
                    <<<'SQL'
INSERT INTO gar_import_files (
    operation_id, file_key, relative_path, descriptor_key, table_name, file_size, file_mtime
) VALUES ($1, $2, $3, $4, $5, $6, $7)
SQL,
                    [
                        (string) $operationId,
                        $file->key,
                        $file->relativePath,
                        $file->descriptor->key,
                        $file->descriptor->table,
                        (string) $file->size,
                        (string) $file->modifiedAt,
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

    /** @param list<string> $rows */
    public function mergeBatch(
        int $operationId,
        GarXmlSourceFile $file,
        array $rows,
        int $processedRows,
    ): void {
        if ($rows === []) {
            return;
        }

        $this->query('BEGIN');

        try {
            $columns = implode(', ', $file->descriptor->stageColumns());
            $this->query(sprintf(
                'CREATE TEMP TABLE %s ON COMMIT DROP AS SELECT %s FROM %s WITH NO DATA',
                self::STAGE_TABLE,
                $columns,
                $file->descriptor->table,
            ));

            if (!pg_copy_from($this->connection, self::STAGE_TABLE, $rows)) {
                throw new GarImportFailed('PostgreSQL COPY failed: ' . pg_last_error($this->connection));
            }

            $merge = $this->query($file->descriptor->mergeSql(self::STAGE_TABLE));
            $mergedRows = pg_affected_rows($merge);
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
                [(string) $operationId, (string) count($rows), (string) $mergedRows],
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
            if (pg_affected_rows($updated) === 1) {
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
SET status = 'completed', completed_at = now(), updated_at = now(), failed_at = NULL, error_message = NULL
WHERE id = $1 AND files_completed = files_total
SQL,
            [(string) $operationId],
        );
        if (pg_affected_rows($updated) !== 1) {
            throw new GarImportFailed('GAR import cannot be completed before every catalog file is complete.');
        }
    }

    public function failOperation(int $operationId, ?string $fileKey, Throwable $error): void
    {
        $message = substr($error->getMessage(), 0, 2000);

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

    public function result(int $operationId, bool $resumed, bool $alreadyCompleted): ImportGarResult
    {
        $row = $this->row($this->queryParams(
            <<<'SQL'
SELECT files_total, files_completed, rows_processed, rows_merged
FROM gar_import_operations
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
        );
    }

    private function resumeOperation(int $operationId, int $batchSize): void
    {
        $this->queryParams(
            <<<'SQL'
UPDATE gar_import_operations
SET status = 'running', batch_size = $2, attempt = attempt + 1, updated_at = now(),
    completed_at = NULL, failed_at = NULL, error_message = NULL
WHERE id = $1
SQL,
            [(string) $operationId, (string) $batchSize],
        );
    }

    private function query(string $sql): Result
    {
        $result = pg_query($this->connection, $sql);
        if (!$result instanceof Result) {
            throw new GarImportFailed('PostgreSQL query failed: ' . pg_last_error($this->connection));
        }

        return $result;
    }

    /** @param list<string> $parameters */
    private function queryParams(string $sql, array $parameters): Result
    {
        $result = pg_query_params($this->connection, $sql, $parameters);
        if (!$result instanceof Result) {
            throw new GarImportFailed('PostgreSQL query failed: ' . pg_last_error($this->connection));
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
        $row = pg_fetch_assoc($result);
        if (!is_array($row)) {
            return null;
        }

        $normalized = [];
        foreach ($row as $key => $value) {
            if (!is_string($key)) {
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
        if (!is_string($value) || !ctype_digit($value)) {
            throw new GarImportFailed("PostgreSQL returned an invalid integer field: {$key}");
        }

        return (int) $value;
    }

    /** @param array<string, string|null> $row */
    private static function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new GarImportFailed("PostgreSQL returned an invalid string field: {$key}");
        }

        return $value;
    }

    private function rollback(): void
    {
        pg_query($this->connection, 'ROLLBACK');
    }
}
