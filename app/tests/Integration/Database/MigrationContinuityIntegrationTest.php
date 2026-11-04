<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Database\Migration\MigrationHistoryContract;
use App\Infrastructure\Postgres\PostgresPoolFactory;
use App\Tests\Support\Database\IntegrationDatabase;
use RuntimeException;
use Testo\Assert;
use Testo\Test;

#[Test]
final class MigrationContinuityIntegrationTest
{
    public function testItBuildsTheCurrentSchemaFromZeroAndRollsBack(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 1);

        try {
            IntegrationDatabase::resetSchema($pool);

            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', ['--force-yes' => true]);

            $history = [];
            $result = $pool->query('SELECT name FROM migration ORDER BY name');
            while (($row = $result->fetchRow()) !== null) {
                $name = $row['name'] ?? null;
                if (!is_string($name)) {
                    throw new RuntimeException('Migration history contains an invalid identifier.');
                }
                $history[] = $name;
            }

            Assert::same($history, MigrationHistoryContract::CLASSES);

            $schema = $pool->query(<<<'SQL'
SELECT
    to_regclass('public.reestr_objects')::text AS reestr_objects,
    to_regclass('public.address_search')::text AS address_search,
    to_regclass('public.address_search_build_progress')::text AS build_progress,
    to_regclass('public.gar_import_index_snapshots')::text AS import_snapshots,
    to_regclass('public.datamos_address_registry')::text AS datamos_registry,
    to_regclass('public.address_aliases')::text AS address_aliases,
    to_regclass('public.idx_object_params_denorm_type_object_update')::text AS denorm_index,
    to_regclass('public.idx_address_search_region_hierarchy')::text AS region_index,
    (
        SELECT is_generated
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'address_search'
          AND column_name = 'region_code'
    ) AS region_generated
SQL)->fetchRow();

            Assert::notNull($schema);
            Assert::same($schema['reestr_objects'] ?? null, 'reestr_objects');
            Assert::same($schema['address_search'] ?? null, 'address_search');
            Assert::same($schema['build_progress'] ?? null, 'address_search_build_progress');
            Assert::same($schema['import_snapshots'] ?? null, 'gar_import_index_snapshots');
            Assert::same($schema['datamos_registry'] ?? null, 'datamos_address_registry');
            Assert::same($schema['address_aliases'] ?? null, 'address_aliases');
            Assert::same($schema['denorm_index'] ?? null, 'idx_object_params_denorm_type_object_update');
            Assert::same($schema['region_index'] ?? null, 'idx_address_search_region_hierarchy');
            Assert::same($schema['region_generated'] ?? null, 'ALWAYS');

            $extensions = [];
            $result = $pool->query(
                "SELECT extname FROM pg_extension WHERE extname IN ('hstore', 'pg_trgm', 'postgis') ORDER BY extname",
            );
            while (($row = $result->fetchRow()) !== null) {
                $extension = $row['extname'] ?? null;
                if (!is_string($extension)) {
                    throw new RuntimeException('PostgreSQL returned an invalid extension name.');
                }
                $extensions[] = $extension;
            }

            Assert::same($extensions, ['hstore', 'pg_trgm', 'postgis']);

            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:down', [
                '--all' => true,
                '--force-yes' => true,
            ]);

            $rollback = $pool->query(<<<'SQL'
SELECT
    (SELECT count(*) FROM migration) AS history_count,
    to_regclass('public.reestr_objects')::text AS reestr_objects,
    to_regclass('public.address_search')::text AS address_search,
    to_regclass('public.address_aliases')::text AS address_aliases
SQL)->fetchRow();

            Assert::notNull($rollback);
            Assert::same((int) ($rollback['history_count'] ?? -1), 0);
            Assert::null($rollback['reestr_objects'] ?? null);
            Assert::null($rollback['address_search'] ?? null);
            Assert::null($rollback['address_aliases'] ?? null);
        } finally {
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }
}
