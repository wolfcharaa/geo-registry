<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use PgSql\Connection;
use PgSql\Result;

/** Records one already-COPYed row range inside the caller's transaction. */
final readonly class GarDirectCopyRangeLedger
{
    public function __construct(private Connection $connection) {}

    public function record(
        int $operationId,
        string $fileKey,
        int $firstRow,
        int $lastRow,
        int $rowCount,
        int $encodedBytes,
    ): int {
        if (
            $firstRow < 1
            || $lastRow < $firstRow
            || $lastRow - $firstRow + 1 !== $rowCount
            || $encodedBytes < 1
        ) {
            throw new GarImportFailed('GAR pipeline batch must describe one encoded contiguous row range.');
        }

        $file = $this->row($this->queryParams(
            <<<'SQL'
SELECT file.status, file.processed_rows, file.pipeline_enabled,
       operation.status AS operation_status, operation.write_mode
FROM gar_import_files file
JOIN gar_import_operations operation ON operation.id = file.operation_id
WHERE file.operation_id = $1 AND file.file_key = $2
FOR UPDATE OF file
SQL,
            [(string) $operationId, $fileKey],
        ));
        if (
            self::string($file, 'status') === 'completed'
            || self::string($file, 'operation_status') !== 'running'
            || self::string($file, 'write_mode') !== 'direct-copy'
            || !self::boolean($file, 'pipeline_enabled')
        ) {
            throw new GarImportFailed('GAR pipeline batch does not match its running direct-copy operation.');
        }
        if ($firstRow <= self::integer($file, 'processed_rows')) {
            throw new GarImportFailed('GAR pipeline batch overlaps the contiguous file checkpoint.');
        }

        $overlap = $this->row($this->queryParams(
            <<<'SQL'
SELECT EXISTS (
    SELECT 1
    FROM gar_import_file_batches
    WHERE operation_id = $1 AND file_key = $2
      AND first_row <= $4 AND last_row >= $3
) AS value
SQL,
            [(string) $operationId, $fileKey, (string) $firstRow, (string) $lastRow],
        ));
        if (($overlap['value'] ?? null) !== 'f') {
            throw new GarImportFailed('GAR pipeline batch overlaps an already committed row range.');
        }

        $this->queryParams(
            <<<'SQL'
INSERT INTO gar_import_file_batches (
    operation_id, file_key, first_row, last_row, encoded_bytes
) VALUES ($1, $2, $3, $4, $5)
SQL,
            [
                (string) $operationId,
                $fileKey,
                (string) $firstRow,
                (string) $lastRow,
                (string) $encodedBytes,
            ],
        );

        $processedRows = self::integer($file, 'processed_rows');
        $rangeRows = $this->queryParams(
            <<<'SQL'
SELECT first_row, last_row
FROM gar_import_file_batches
WHERE operation_id = $1 AND file_key = $2
ORDER BY first_row
SQL,
            [(string) $operationId, $fileKey],
        );
        $ranges = [];
        while (($range = $this->optionalRow($rangeRows)) !== null) {
            $ranges[] = new GarImportCommittedRange(
                self::integer($range, 'first_row'),
                self::integer($range, 'last_row'),
            );
        }
        $processedRows = GarImportCheckpoint::fold($processedRows, $ranges);

        $this->queryParams(
            <<<'SQL'
UPDATE gar_import_files
SET processed_rows = $3, merged_rows = merged_rows + $4, updated_at = now()
WHERE operation_id = $1 AND file_key = $2
SQL,
            [
                (string) $operationId,
                $fileKey,
                (string) $processedRows,
                (string) $rowCount,
            ],
        );
        $this->queryParams(
            <<<'SQL'
UPDATE gar_import_operations
SET rows_processed = rows_processed + $2,
    rows_merged = rows_merged + $2,
    pipeline_batches = pipeline_batches + 1,
    pipeline_bytes = pipeline_bytes + $3,
    updated_at = now()
WHERE id = $1
SQL,
            [(string) $operationId, (string) $rowCount, (string) $encodedBytes],
        );
        $this->queryParams(
            <<<'SQL'
DELETE FROM gar_import_file_batches
WHERE operation_id = $1 AND file_key = $2 AND last_row <= $3
SQL,
            [(string) $operationId, $fileKey, (string) $processedRows],
        );

        return $processedRows;
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
}
