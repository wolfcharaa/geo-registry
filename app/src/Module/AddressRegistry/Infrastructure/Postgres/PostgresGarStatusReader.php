<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\Postgres;

use Amp\Postgres\PostgresConnectionPool;
use App\Module\AddressRegistry\Capability\Status\AddressSearchRebuildStatus;
use App\Module\AddressRegistry\Capability\Status\GarImportStatus;
use App\Module\AddressRegistry\Capability\Status\GarRegionStatus;
use App\Module\AddressRegistry\Capability\Status\GarStatusReader;
use App\Module\AddressRegistry\Capability\Status\GarStatusSnapshot;
use RuntimeException;
use Throwable;

final readonly class PostgresGarStatusReader implements GarStatusReader
{
    public function __construct(private PostgresConnectionPool $postgres) {}

    public function read(array $regions, bool $exact): GarStatusSnapshot
    {
        try {
            $version = $this->postgres->query(
                'SELECT version, ver_date::text AS ver_date FROM gar_versions ORDER BY ver_date DESC, id DESC LIMIT 1',
            )->fetchRow();
            $import = $this->importStatus();
            $rebuild = $this->rebuildStatus();

            return new GarStatusSnapshot(
                garVersion: self::nullableString($version, 'version'),
                garVersionDate: self::nullableString($version, 'ver_date'),
                import: $import,
                rebuild: $rebuild,
                regions: $this->regionStatuses($regions, $rebuild?->operationId, $exact),
                exact: $exact,
            );
        } catch (Throwable $error) {
            throw new RuntimeException('Unable to read GAR operational status.', previous: $error);
        }
    }

    private function importStatus(): ?GarImportStatus
    {
        $row = $this->postgres->query(<<<'SQL'
SELECT
    operation.id,
    operation.status,
    operation.mode,
    operation.phase,
    operation.write_mode,
    operation.attempt,
    operation.batch_size,
    operation.source_path,
    operation.manifest_hash,
    operation.files_completed,
    operation.files_total,
    operation.rows_processed,
    operation.rows_merged,
    operation.updated_at::text AS updated_at,
    (clock_timestamp() - operation.updated_at)::text AS progress_age,
    operation.pipeline_writers,
    operation.pipeline_batches,
    operation.pipeline_bytes,
    operation.pipeline_peak_in_flight_batches,
    operation.pipeline_peak_in_flight_bytes,
    CASE WHEN operation.defer_foreign_keys THEN 1 ELSE 0 END AS defer_foreign_keys,
    operation.foreign_keys_completed,
    operation.foreign_keys_total,
    pipeline.pipeline_file,
    pipeline.pipeline_file_status,
    CASE
        WHEN pipeline.updated_at IS NULL THEN NULL
        ELSE (clock_timestamp() - pipeline.updated_at)::text
    END AS pipeline_progress_age,
    coalesce(backlog.pending_ranges, 0)::bigint AS pipeline_pending_ranges,
    coalesce(backlog.pending_rows, 0)::bigint AS pipeline_pending_rows
FROM gar_import_operations operation
LEFT JOIN LATERAL (
    SELECT
        CASE
            WHEN count(*) = 0 THEN NULL
            WHEN count(*) = 1 THEN min(file.relative_path)
            ELSE 'auto:' || count(*)::text
        END AS pipeline_file,
        CASE
            WHEN count(*) = 0 THEN NULL
            WHEN bool_and(file.status = 'completed') THEN 'completed'
            WHEN bool_or(file.status = 'failed') THEN 'failed'
            WHEN bool_or(file.status = 'running') THEN 'running'
            ELSE 'pending'
        END AS pipeline_file_status,
        max(file.updated_at) AS updated_at
    FROM gar_import_files file
    WHERE file.operation_id = operation.id
      AND file.pipeline_enabled
) pipeline ON true
LEFT JOIN LATERAL (
    SELECT
        count(*)::bigint AS pending_ranges,
        coalesce(sum(batch.last_row - batch.first_row + 1), 0)::bigint AS pending_rows
    FROM gar_import_file_batches batch
    WHERE batch.operation_id = operation.id
) backlog ON true
ORDER BY operation.id DESC
LIMIT 1
SQL)->fetchRow();
        if ($row === null) {
            return null;
        }

        $filesByStatus = [];
        foreach ($this->postgres->execute(
            'SELECT status, count(*)::bigint AS files FROM gar_import_files WHERE operation_id = $1 GROUP BY status ORDER BY status',
            [self::integer($row, 'id')],
        ) as $fileRow) {
            $status = match (self::string($fileRow, 'status')) {
                'pending' => 'pending',
                'running' => 'running',
                'completed' => 'completed',
                'failed' => 'failed',
                default => throw new RuntimeException('PostgreSQL returned an unknown GAR import file status.'),
            };
            $filesByStatus[$status] = self::integer($fileRow, 'files');
        }
        return new GarImportStatus(
            operationId: self::integer($row, 'id'),
            status: self::string($row, 'status'),
            mode: self::string($row, 'mode'),
            phase: self::string($row, 'phase'),
            writeMode: self::string($row, 'write_mode'),
            attempt: self::integer($row, 'attempt'),
            batchSize: self::integer($row, 'batch_size'),
            sourcePath: self::string($row, 'source_path'),
            manifestHash: self::string($row, 'manifest_hash'),
            filesCompleted: self::integer($row, 'files_completed'),
            filesTotal: self::integer($row, 'files_total'),
            rowsProcessed: self::integer($row, 'rows_processed'),
            rowsMerged: self::integer($row, 'rows_merged'),
            updatedAt: self::string($row, 'updated_at'),
            pendingFiles: $filesByStatus['pending'] ?? 0,
            runningFiles: $filesByStatus['running'] ?? 0,
            completedFiles: $filesByStatus['completed'] ?? 0,
            failedFiles: $filesByStatus['failed'] ?? 0,
            progressAge: self::string($row, 'progress_age'),
            pipelineState: self::pipelineState($row),
            pipelineFile: self::nullableString($row, 'pipeline_file'),
            pipelineWriters: self::nullableInteger($row, 'pipeline_writers'),
            pipelineBatches: self::integer($row, 'pipeline_batches'),
            pipelineBytes: self::integer($row, 'pipeline_bytes'),
            pipelinePeakInFlightBatches: self::integer($row, 'pipeline_peak_in_flight_batches'),
            pipelinePeakInFlightBytes: self::integer($row, 'pipeline_peak_in_flight_bytes'),
            pipelinePendingRanges: self::integer($row, 'pipeline_pending_ranges'),
            pipelinePendingRows: self::integer($row, 'pipeline_pending_rows'),
            pipelineProgressAge: self::nullableString($row, 'pipeline_progress_age'),
            deferForeignKeys: self::integer($row, 'defer_foreign_keys') === 1,
            foreignKeysCompleted: self::integer($row, 'foreign_keys_completed'),
            foreignKeysTotal: self::integer($row, 'foreign_keys_total'),
        );
    }

    /** @param array<array-key, mixed> $row */
    private static function pipelineState(array $row): string
    {
        if (self::nullableString($row, 'pipeline_file') === null) {
            return 'off';
        }
        $status = self::string($row, 'status');
        if ($status === 'failed' || $status === 'abandoned') {
            return 'recovery-required';
        }
        if (self::integer($row, 'pipeline_pending_ranges') > 0) {
            return 'waiting-for-gap';
        }
        if ($status === 'completed' || self::nullableString($row, 'pipeline_file_status') === 'completed') {
            return 'completed';
        }
        if ($status === 'running' && self::integer($row, 'attempt') > 1) {
            return 'recovering';
        }
        if (self::nullableString($row, 'pipeline_file_status') === 'running') {
            return 'running';
        }

        return 'pending';
    }

    private function rebuildStatus(): ?AddressSearchRebuildStatus
    {
        $row = $this->postgres->query(<<<'SQL'
SELECT
    id,
    status,
    phase,
    tasks_completed,
    tasks_total,
    rows_scanned,
    rows_written,
    shadow_rows,
    updated_at::text AS updated_at
FROM address_search_rebuild_operations
ORDER BY id DESC
LIMIT 1
SQL)->fetchRow();
        if ($row === null) {
            return null;
        }

        return new AddressSearchRebuildStatus(
            operationId: self::integer($row, 'id'),
            status: self::string($row, 'status'),
            phase: self::string($row, 'phase'),
            tasksCompleted: self::integer($row, 'tasks_completed'),
            tasksTotal: self::integer($row, 'tasks_total'),
            rowsScanned: self::integer($row, 'rows_scanned'),
            rowsWritten: self::integer($row, 'rows_written'),
            projectionRows: self::integer($row, 'shadow_rows'),
            updatedAt: self::string($row, 'updated_at'),
        );
    }

    /**
     * @param non-empty-list<int> $regions
     * @return list<GarRegionStatus>
     */
    private function regionStatuses(array $regions, ?int $operationId, bool $exact): array
    {
        $regionList = \implode(', ', $regions);
        $checkpoints = [];
        if ($operationId !== null) {
            foreach ($this->postgres->execute(<<<'SQL'
SELECT region_code, hierarchy_type, status, written_rows
FROM address_search_rebuild_regions
WHERE operation_id = $1
ORDER BY region_code, hierarchy_type
SQL, [$operationId]) as $row) {
                $checkpoints[self::integer($row, 'region_code') . ':' . self::string($row, 'hierarchy_type')] = $row;
            }
        }

        $coverage = [];
        if ($exact) {
            foreach ($this->postgres->query(<<<SQL
WITH alias_counts AS (
    SELECT object_id, hierarchy_type, count(*)::bigint AS alias_rows
    FROM address_aliases
    GROUP BY object_id, hierarchy_type
)
SELECT
    search.region_code,
    search.hierarchy_type,
    count(*)::bigint AS projection_rows,
    count(*) FILTER (WHERE search.geo_lat IS NOT NULL AND search.geo_lon IS NOT NULL)::bigint AS geocoded_rows,
    count(*) FILTER (WHERE search.datamos_global_id IS NOT NULL)::bigint AS datamos_rows,
    coalesce(sum(alias_counts.alias_rows), 0)::bigint AS alias_rows
FROM address_search search
LEFT JOIN alias_counts USING (object_id, hierarchy_type)
WHERE search.region_code IN ({$regionList})
GROUP BY search.region_code, search.hierarchy_type
ORDER BY search.region_code, search.hierarchy_type
SQL) as $row) {
                $coverage[self::integer($row, 'region_code') . ':' . self::string($row, 'hierarchy_type')] = $row;
            }
        }

        $statuses = [];
        foreach ($regions as $regionCode) {
            foreach (['adm', 'mun'] as $hierarchy) {
                $key = $regionCode . ':' . $hierarchy;
                $checkpoint = $checkpoints[$key] ?? null;
                $exactRow = $coverage[$key] ?? null;
                $statuses[] = new GarRegionStatus(
                    regionCode: $regionCode,
                    hierarchy: $hierarchy,
                    checkpointStatus: self::nullableString($checkpoint, 'status') ?? 'missing',
                    checkpointRows: self::nullableInteger($checkpoint, 'written_rows') ?? 0,
                    projectionRows: self::nullableInteger($exactRow, 'projection_rows'),
                    geocodedRows: self::nullableInteger($exactRow, 'geocoded_rows'),
                    dataMosRows: self::nullableInteger($exactRow, 'datamos_rows'),
                    aliasRows: self::nullableInteger($exactRow, 'alias_rows'),
                );
            }
        }

        return $statuses;
    }

    /** @param array<array-key, mixed>|null $row */
    private static function nullableInteger(?array $row, string $field): ?int
    {
        return $row === null || !\array_key_exists($field, $row) || $row[$field] === null
            ? null
            : self::integer($row, $field);
    }

    /** @param array<array-key, mixed>|null $row */
    private static function nullableString(?array $row, string $field): ?string
    {
        return $row === null || !\array_key_exists($field, $row) || $row[$field] === null
            ? null
            : self::string($row, $field);
    }

    /** @param array<array-key, mixed> $row */
    private static function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && \ctype_digit($value)) {
            return (int) $value;
        }

        throw new RuntimeException("PostgreSQL field `{$field}` must be a non-negative integer.");
    }

    /** @param array<array-key, mixed> $row */
    private static function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!\is_string($value)) {
            throw new RuntimeException("PostgreSQL field `{$field}` must be a string.");
        }

        return $value;
    }
}
