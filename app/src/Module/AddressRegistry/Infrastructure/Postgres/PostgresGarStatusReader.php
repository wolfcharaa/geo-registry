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
    id,
    status,
    source_path,
    manifest_hash,
    files_completed,
    files_total,
    rows_processed,
    rows_merged,
    updated_at::text AS updated_at
FROM gar_import_operations
ORDER BY id DESC
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
        );
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
        $regionList = implode(', ', $regions);
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
        return $row === null || !array_key_exists($field, $row) || $row[$field] === null
            ? null
            : self::integer($row, $field);
    }

    /** @param array<array-key, mixed>|null $row */
    private static function nullableString(?array $row, string $field): ?string
    {
        return $row === null || !array_key_exists($field, $row) || $row[$field] === null
            ? null
            : self::string($row, $field);
    }

    /** @param array<array-key, mixed> $row */
    private static function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new RuntimeException("PostgreSQL field `{$field}` must be a non-negative integer.");
    }

    /** @param array<array-key, mixed> $row */
    private static function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException("PostgreSQL field `{$field}` must be a string.");
        }

        return $value;
    }
}
