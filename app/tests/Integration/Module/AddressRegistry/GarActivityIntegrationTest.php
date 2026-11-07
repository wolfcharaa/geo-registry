<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\AddressRegistry;

use function Amp\async;
use function Amp\delay;

use Amp\Future;
use Amp\Postgres\PostgresConnectionPool;
use App\Infrastructure\Postgres\PostgresPoolFactory;
use App\Module\AddressRegistry\Capability\Activity\PostgresStatementActivity;
use App\Module\AddressRegistry\Infrastructure\Postgres\PostgresGarActivityReader;
use App\Tests\Support\Database\IntegrationDatabase;
use Testo\Assert;
use Testo\Test;

#[Test]
final class GarActivityIntegrationTest
{
    public function testItReadsLiveSqlAndDurableTargetActivity(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 3);
        /** @var Future<mixed>|null $activeQuery */
        $activeQuery = null;

        try {
            IntegrationDatabase::resetSchema($pool);
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', ['--force-yes' => true]);
            $this->seedActivity($pool);

            $activeQuery = async(static fn(): mixed => $pool->query(
                "SELECT pg_sleep(0.5), 'gar-activity-integration'",
            ));
            delay(0.05);
            $snapshot = (new PostgresGarActivityReader($pool))->read(20);

            Assert::same(count($snapshot->operations), 2);
            $import = $snapshot->operations[0] ?? null;
            $rebuild = $snapshot->operations[1] ?? null;
            Assert::notNull($import);
            Assert::notNull($rebuild);
            Assert::same($import->type, 'gar_import');
            Assert::same($rebuild->type, 'address_search_rebuild');
            Assert::same(count($snapshot->workers), 1);
            $worker = $snapshot->workers[0] ?? null;
            Assert::notNull($worker);
            Assert::same($worker->instanceId, 'worker-integration');
            Assert::same(count($snapshot->queues), 1);
            $queue = $snapshot->queues[0] ?? null;
            Assert::notNull($queue);
            Assert::same($queue->bindingId, 'gar.address_coordinates.update');
            Assert::true(array_any(
                $snapshot->statements,
                static fn(PostgresStatementActivity $statement): bool => str_contains(
                    $statement->query,
                    'gar-activity-integration',
                ),
            ));
        } finally {
            $activeQuery?->await();
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }

    private function seedActivity(PostgresConnectionPool $pool): void
    {
        $pool->query(<<<'SQL'
INSERT INTO gar_import_operations (
    source_key, source_path, manifest_hash, status, batch_size,
    files_total, files_completed, rows_processed, rows_merged
) VALUES (
    repeat('a', 64), '/fixture/gar', repeat('b', 64), 'running', 50000,
    2, 1, 100, 90
)
SQL);
        $pool->query(<<<'SQL'
INSERT INTO address_search_rebuild_operations (
    status, phase, shadow_table, batch_size,
    tasks_total, tasks_completed, rows_scanned, rows_written
) VALUES (
    'ready', 'ready', 'address_search_activity_shadow', 50000,
    2, 2, 100, 100
)
SQL);
        $pool->query(<<<'SQL'
INSERT INTO message_bus__worker_instances (
    worker_instance_id, worker_name, host, pid, started_at, mode,
    transport, queue, state, activity, heartbeat_at, updated_at
) VALUES (
    'worker-integration', 'runtime', 'integration', 100, now(), 'fiber',
    'postgres', 'runtime', 'running', 'idle', now(), now()
)
SQL);
        $pool->query(<<<'SQL'
INSERT INTO message_bus__queue_jobs (
    transport, queue, status, message_name, message_id, correlation_id,
    flow, binding_id, max_attempts, retry_policy_key, retry_policy_snapshot,
    available_at, serialized_envelope, created_at, updated_at
) VALUES (
    'postgres', 'runtime', 'pending', 'address-coordinates-resolved',
    'message-1', 'correlation-1', 'runtime_async', 'gar.address_coordinates.update',
    3, 'default', '{}'::jsonb, now(), '{}'::jsonb, now(), now()
)
SQL);
    }
}
