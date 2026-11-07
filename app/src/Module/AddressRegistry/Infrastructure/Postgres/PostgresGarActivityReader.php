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
    id,
    status,
    files_completed,
    files_total,
    rows_processed,
    rows_merged,
    updated_at::text AS updated_at,
    error_message
FROM gar_import_operations
ORDER BY id DESC
LIMIT 1
SQL)->fetchRow();
        if ($import !== null) {
            $operations[] = new TargetOperationActivity(
                type: 'gar_import',
                operationId: self::integer($import, 'id'),
                status: self::string($import, 'status'),
                phase: null,
                stepsCompleted: self::integer($import, 'files_completed'),
                stepsTotal: self::integer($import, 'files_total'),
                rowsRead: self::integer($import, 'rows_processed'),
                rowsWritten: self::integer($import, 'rows_merged'),
                updatedAt: self::string($import, 'updated_at'),
                error: self::nullableString($import, 'error_message'),
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
                error: self::nullableString($rebuild, 'error_message'),
            );
        }

        return $operations;
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
        if (is_int($value) && $value >= 0) {
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

    /** @param array<array-key, mixed> $row */
    private static function nullableString(array $row, string $field): ?string
    {
        return ($row[$field] ?? null) === null ? null : self::string($row, $field);
    }
}
