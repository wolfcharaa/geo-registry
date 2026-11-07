<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\AddressRegistry;

use Amp\Postgres\PostgresConnectionPool;
use App\Infrastructure\Postgres\PostgresPoolFactory;
use App\Module\AddressRegistry\Infrastructure\Postgres\PostgresGarIntegrityChecker;
use App\Tests\Support\Database\AddressRegistryParityFixture;
use App\Tests\Support\Database\IntegrationDatabase;
use Testo\Assert;
use Testo\Test;

#[Test]
final class GarIntegrityIntegrationTest
{
    public function testItPassesTheRegionalDeepGateAndFailsOnBrokenParity(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 2);

        try {
            IntegrationDatabase::resetSchema($pool);
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', ['--force-yes' => true]);
            AddressRegistryParityFixture::load($pool);
            IntegrationDatabase::runMigrationCommand($dsn, 'gar:address-search:rebuild', [
                '--batch-size' => '2',
            ]);
            self::recordCompletedImport($pool);

            $checker = new PostgresGarIntegrityChecker($pool);
            $deep = $checker->check([77, 78], true);

            Assert::true($deep->isValid());
            Assert::same(count($deep->checks), 13);
            Assert::same($deep->failedChecks(), 0);
            Assert::same($deep->unavailableChecks(), 0);

            $pool->query(<<<'SQL'
UPDATE address_search_rebuild_operations
SET missing_rows = 1
WHERE id = (SELECT id FROM address_search_rebuild_operations ORDER BY id DESC LIMIT 1)
SQL);
            $broken = $checker->check([77, 78], false);

            Assert::false($broken->isValid());
            Assert::same($broken->failedChecks(), 1);
            Assert::same($broken->unavailableChecks(), 0);
        } finally {
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }

    private static function recordCompletedImport(PostgresConnectionPool $pool): void
    {
        $operation = $pool->query(<<<'SQL'
INSERT INTO gar_import_operations (
    source_key,
    source_path,
    manifest_hash,
    status,
    batch_size,
    files_total,
    files_completed,
    rows_processed,
    rows_merged,
    completed_at
) VALUES (
    repeat('a', 64),
    '/fixture/gar',
    repeat('b', 64),
    'completed',
    50000,
    1,
    1,
    20,
    20,
    now()
)
RETURNING id
SQL)->fetchRow();
        $operationId = $operation['id'] ?? null;
        Assert::true(is_int($operationId) || is_string($operationId));

        $pool->execute(<<<'SQL'
INSERT INTO gar_import_files (
    operation_id,
    file_key,
    relative_path,
    descriptor_key,
    table_name,
    file_size,
    file_mtime,
    status,
    processed_rows,
    merged_rows,
    completed_at
) VALUES (
    $1,
    repeat('c', 64),
    'fixture.xml',
    'fixture',
    'reestr_objects',
    1,
    1,
    'completed',
    20,
    20,
    now()
)
SQL, [$operationId]);
    }
}
