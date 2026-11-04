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
    to_regclass('public.gar_import_operations')::text AS import_operations,
    to_regclass('public.gar_import_files')::text AS import_files,
    to_regclass('public.address_search_rebuild_operations')::text AS rebuild_operations,
    to_regclass('public.address_search_rebuild_regions')::text AS rebuild_regions,
    to_regclass('public.message_bus__queue_jobs')::text AS queue_jobs,
    to_regclass('public.message_bus__worker_control_commands')::text AS worker_commands,
    to_regclass('public.message_bus__worker_desired_state')::text AS worker_desired_state,
    to_regclass('public.message_bus__worker_instances')::text AS worker_instances,
    to_regclass('public.message_bus__worker_child_instances')::text AS worker_children,
    to_regclass('public.message_bus__worker_control_command_deliveries')::text AS worker_deliveries,
    to_regclass('public.message_bus__worker_control_command_acknowledgements')::text AS worker_acknowledgements,
    to_regclass('public.message_bus__worker_control_command_audit')::text AS worker_audit,
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
            Assert::same($schema['region_generated'] ?? null, 'NEVER');
            Assert::same($schema['import_operations'] ?? null, 'gar_import_operations');
            Assert::same($schema['import_files'] ?? null, 'gar_import_files');
            Assert::same($schema['rebuild_operations'] ?? null, 'address_search_rebuild_operations');
            Assert::same($schema['rebuild_regions'] ?? null, 'address_search_rebuild_regions');
            Assert::same($schema['queue_jobs'] ?? null, 'message_bus__queue_jobs');
            Assert::same($schema['worker_commands'] ?? null, 'message_bus__worker_control_commands');
            Assert::same($schema['worker_desired_state'] ?? null, 'message_bus__worker_desired_state');
            Assert::same($schema['worker_instances'] ?? null, 'message_bus__worker_instances');
            Assert::same($schema['worker_children'] ?? null, 'message_bus__worker_child_instances');
            Assert::same($schema['worker_deliveries'] ?? null, 'message_bus__worker_control_command_deliveries');
            Assert::same(
                $schema['worker_acknowledgements'] ?? null,
                'message_bus__worker_control_command_acknowledgements',
            );
            Assert::same($schema['worker_audit'] ?? null, 'message_bus__worker_control_command_audit');

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

    public function testItReplacesTheOkatoShortcutWithTheActiveGarRegion(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 1);

        try {
            IntegrationDatabase::resetSchema($pool);
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', [
                '--limit' => count(MigrationHistoryContract::CLASSES) - 1,
                '--force-yes' => true,
            ]);
            $pool->query(<<<'SQL'
INSERT INTO object_levels (id, name, is_active, start_date, end_date, update_date)
VALUES (1, 'Регион', true, '2020-01-01', '2079-06-06', '2026-01-01')
SQL);
            $pool->query(<<<'SQL'
INSERT INTO reestr_objects (
    id, object_guid, change_id, is_active, level_id, create_date, update_date
) VALUES (
    1411051, '00000000-0000-4000-8000-000000141051', 1411051, true, 1,
    '2020-01-01', '2026-01-01'
)
SQL);
            $pool->query(<<<'SQL'
INSERT INTO administrative_reestr_hierarchy (
    id, object_id, region_code, update_date, start_date, end_date, is_active, path, path_arr
) VALUES (
    1411051, 1411051, 77, '2026-01-01', '2020-01-01', '2079-06-06', true,
    '1411051', ARRAY[1411051]
)
SQL);
            $pool->query(<<<'SQL'
INSERT INTO address_search (
    object_id, object_guid, address_string, hierarchy_type, level, okato
) VALUES (
    1411051, '00000000-0000-4000-8000-000000141051', 'г. Москва', 'adm', 1,
    '45293594000'
)
SQL);

            $legacy = $pool->query(
                'SELECT region_code FROM address_search WHERE object_id = 1411051',
            )->fetchRow();
            Assert::notNull($legacy);
            Assert::same((int) ($legacy['region_code'] ?? -1), 45);

            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', [
                '--limit' => 1,
                '--force-yes' => true,
            ]);

            $corrected = $pool->query(<<<'SQL'
SELECT
    search.region_code,
    columns.is_generated
FROM address_search search
JOIN information_schema.columns columns
  ON columns.table_schema = 'public'
 AND columns.table_name = 'address_search'
 AND columns.column_name = 'region_code'
WHERE search.object_id = 1411051
SQL)->fetchRow();
            Assert::notNull($corrected);
            Assert::same((int) ($corrected['region_code'] ?? -1), 77);
            Assert::same($corrected['is_generated'] ?? null, 'NEVER');

            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:down', [
                '--limit' => 1,
                '--force-yes' => true,
            ]);

            $reverted = $pool->query(<<<'SQL'
SELECT
    search.region_code,
    columns.is_generated
FROM address_search search
JOIN information_schema.columns columns
  ON columns.table_schema = 'public'
 AND columns.table_name = 'address_search'
 AND columns.column_name = 'region_code'
WHERE search.object_id = 1411051
SQL)->fetchRow();
            Assert::notNull($reverted);
            Assert::same((int) ($reverted['region_code'] ?? -1), 45);
            Assert::same($reverted['is_generated'] ?? null, 'ALWAYS');
        } finally {
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }
}
