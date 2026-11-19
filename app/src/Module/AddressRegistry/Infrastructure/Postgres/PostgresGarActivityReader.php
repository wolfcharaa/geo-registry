<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\Postgres;

use Amp\Postgres\PostgresConnectionPool;
use App\Module\AddressRegistry\Capability\Activity\GarActivityReader;
use App\Module\AddressRegistry\Capability\Activity\GarActivitySnapshot;
use App\Module\AddressRegistry\Capability\Activity\PostgresStatementActivity;
use App\Module\AddressRegistry\Capability\Activity\TargetOperationActivity;
use App\Module\AddressRegistry\Capability\Activity\WorkerInstanceActivity;
use App\Module\AddressRegistry\Capability\Activity\WorkerQueueActivity;
use RuntimeException;
use Throwable;

final readonly class PostgresGarActivityReader implements GarActivityReader
{
    public function __construct(private PostgresConnectionPool $postgres) {}

    public function read(int $limit): GarActivitySnapshot
    {
        if ($limit < 1 || $limit > 100) {
            throw new RuntimeException('GAR activity limit must be between 1 and 100.');
        }

        try {
            return new GarActivitySnapshot(
                statements: $this->statements($limit),
                operations: $this->operations(),
                workers: $this->workers($limit),
                queues: $this->queues($limit),
            );
        } catch (Throwable $error) {
            throw new RuntimeException('Unable to read GAR activity.', previous: $error);
        }
    }

    /** @return list<PostgresStatementActivity> */
    private function statements(int $limit): array
    {
        $statements = [];
        foreach ($this->postgres->execute(<<<'SQL'
SELECT
    pid,
    application_name,
    state,
    (clock_timestamp() - query_start)::text AS age,
    wait_event_type,
    wait_event,
    left(regexp_replace(query, '\s+', ' ', 'g'), 200) AS query
FROM pg_stat_activity
WHERE datname = current_database()
  AND state <> 'idle'
  AND pid <> pg_backend_pid()
ORDER BY query_start
LIMIT $1
SQL, [$limit]) as $row) {
            $statements[] = new PostgresStatementActivity(
                pid: self::integer($row, 'pid'),
                application: self::string($row, 'application_name'),
                state: self::string($row, 'state'),
                age: self::string($row, 'age'),
                waitEventType: self::nullableString($row, 'wait_event_type'),
                waitEvent: self::nullableString($row, 'wait_event'),
                query: self::string($row, 'query'),
            );
        }

        return $statements;
    }

    /** @return list<TargetOperationActivity> */
    private function operations(): array
    {
        $operations = [];
        $import = $this->postgres->query(<<<'SQL'
SELECT
    operation.id,
    operation.status,
    operation.mode,
    operation.phase,
    operation.write_mode,
    operation.attempt,
    operation.batch_size,
    operation.files_completed,
    operation.files_total,
    operation.rows_processed,
    operation.rows_merged,
    operation.updated_at::text AS updated_at,
    (clock_timestamp() - operation.updated_at)::text AS progress_age,
    operation.error_message,
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
        if ($import !== null) {
            $operations[] = new TargetOperationActivity(
                type: 'gar_import',
                operationId: self::integer($import, 'id'),
                status: self::string($import, 'status'),
                phase: self::string($import, 'phase'),
                stepsCompleted: self::integer($import, 'files_completed'),
                stepsTotal: self::integer($import, 'files_total'),
                rowsRead: self::integer($import, 'rows_processed'),
                rowsWritten: self::integer($import, 'rows_merged'),
                updatedAt: self::string($import, 'updated_at'),
                progressAge: self::string($import, 'progress_age'),
                error: self::nullableString($import, 'error_message'),
                mode: self::string($import, 'mode'),
                writeMode: self::string($import, 'write_mode'),
                attempt: self::integer($import, 'attempt'),
                batchSize: self::integer($import, 'batch_size'),
                pipelineState: self::pipelineState($import),
                pipelineFile: self::nullableString($import, 'pipeline_file'),
                pipelineWriters: self::nullableInteger($import, 'pipeline_writers'),
                pipelineBatches: self::integer($import, 'pipeline_batches'),
                pipelineBytes: self::integer($import, 'pipeline_bytes'),
                pipelinePeakInFlightBatches: self::integer($import, 'pipeline_peak_in_flight_batches'),
                pipelinePeakInFlightBytes: self::integer($import, 'pipeline_peak_in_flight_bytes'),
                pipelinePendingRanges: self::integer($import, 'pipeline_pending_ranges'),
                pipelinePendingRows: self::integer($import, 'pipeline_pending_rows'),
                pipelineProgressAge: self::nullableString($import, 'pipeline_progress_age'),
                deferForeignKeys: self::integer($import, 'defer_foreign_keys') === 1,
                foreignKeysCompleted: self::integer($import, 'foreign_keys_completed'),
                foreignKeysTotal: self::integer($import, 'foreign_keys_total'),
            );
        }

        $rebuild = $this->postgres->query(<<<'SQL'
SELECT
    id,
    status,
    phase,
    tasks_completed,
    tasks_total,
    rows_scanned,
    rows_written,
    updated_at::text AS updated_at,
    (clock_timestamp() - updated_at)::text AS progress_age,
    error_message
FROM address_search_rebuild_operations
ORDER BY id DESC
LIMIT 1
SQL)->fetchRow();
        if ($rebuild !== null) {
            $operations[] = new TargetOperationActivity(
                type: 'address_search_rebuild',
                operationId: self::integer($rebuild, 'id'),
                status: self::string($rebuild, 'status'),
                phase: self::string($rebuild, 'phase'),
                stepsCompleted: self::integer($rebuild, 'tasks_completed'),
                stepsTotal: self::integer($rebuild, 'tasks_total'),
                rowsRead: self::integer($rebuild, 'rows_scanned'),
                rowsWritten: self::integer($rebuild, 'rows_written'),
                updatedAt: self::string($rebuild, 'updated_at'),
                progressAge: self::string($rebuild, 'progress_age'),
                error: self::nullableString($rebuild, 'error_message'),
            );
        }

        return $operations;
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

    /** @return list<WorkerInstanceActivity> */
    private function workers(int $limit): array
    {
        $workers = [];
        foreach ($this->postgres->execute(<<<'SQL'
SELECT
    worker_instance_id,
    worker_name,
    state,
    activity,
    transport,
    queue,
    heartbeat_at::text AS heartbeat_at,
    (clock_timestamp() - heartbeat_at)::text AS heartbeat_age
FROM message_bus__worker_instances
WHERE stopped_at IS NULL
ORDER BY heartbeat_at DESC, worker_instance_id
LIMIT $1
SQL, [$limit]) as $row) {
            $workers[] = new WorkerInstanceActivity(
                instanceId: self::string($row, 'worker_instance_id'),
                name: self::string($row, 'worker_name'),
                state: self::string($row, 'state'),
                activity: self::string($row, 'activity'),
                transport: self::string($row, 'transport'),
                queue: self::string($row, 'queue'),
                heartbeatAt: self::string($row, 'heartbeat_at'),
                heartbeatAge: self::string($row, 'heartbeat_age'),
            );
        }

        return $workers;
    }

    /** @return list<WorkerQueueActivity> */
    private function queues(int $limit): array
    {
        $queues = [];
        foreach ($this->postgres->execute(<<<'SQL'
SELECT
    flow,
    binding_id,
    status,
    count(*)::bigint AS jobs,
    min(created_at)::text AS oldest_at
FROM message_bus__queue_jobs
WHERE binding_id LIKE 'gar.%'
  AND status IN ('pending', 'running', 'interrupted', 'failed')
GROUP BY flow, binding_id, status
ORDER BY min(created_at), flow, binding_id, status
LIMIT $1
SQL, [$limit]) as $row) {
            $queues[] = new WorkerQueueActivity(
                flow: self::string($row, 'flow'),
                bindingId: self::string($row, 'binding_id'),
                status: self::string($row, 'status'),
                jobs: self::integer($row, 'jobs'),
                oldestAt: self::string($row, 'oldest_at'),
            );
        }

        return $queues;
    }

    /** @param array<array-key, mixed> $row */
    private static function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (\is_int($value) && $value >= 0) {
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

    /** @param array<array-key, mixed> $row */
    private static function nullableString(array $row, string $field): ?string
    {
        return ($row[$field] ?? null) === null ? null : self::string($row, $field);
    }

    /** @param array<array-key, mixed> $row */
    private static function nullableInteger(array $row, string $field): ?int
    {
        return ($row[$field] ?? null) === null ? null : self::integer($row, $field);
    }
}
