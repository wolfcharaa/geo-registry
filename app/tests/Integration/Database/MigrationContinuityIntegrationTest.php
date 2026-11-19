<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use Amp\Postgres\PostgresConnectionPool;
use App\Database\Migration\M261104040000UseGarRegionCodeForAddressSearch;
use App\Database\Migration\M261112010000DropUnusedGarHierarchyPathIndexes;
use App\Database\Migration\M261112020000DropUnusedGarTrigramIndexes;
use App\Database\Migration\M261112030000DropRedundantGarUnitLifecycleIndexes;
use App\Database\Migration\M261112040000DropDetachedGarVersionLinkIndexes;
use App\Database\Migration\M261112050000DropRedundantObjectParamsLifecycleIndexes;
use App\Database\Migration\M261112060000DropUnusedObjectParamsChangeEndIndex;
use App\Database\Migration\M261112070000DropUnusedGarUnitGuidIndexes;
use App\Database\Migration\M261112080000DropDetachedGarHierarchyCrossLinkIndexes;
use App\Database\Migration\M261113000000DropUnusedGarHierarchyStartUpdateIndexes;
use App\Database\Migration\M261113010000DropUnusedChangeHistoryIndexes;
use App\Database\Migration\M261113020000DropUnusedReestrObjectMetadataIndexes;
use App\Database\Migration\MigrationHistoryContract;
use App\Infrastructure\Postgres\PostgresPoolFactory;
use App\Tests\Support\Database\IntegrationDatabase;
use RuntimeException;
use Testo\Assert;
use Testo\Test;

#[Test]
final class MigrationContinuityIntegrationTest
{
    private const GAR_TRIGRAM_INDEXES = [
        'idx_address_object_types_name',
        'idx_address_object_types_short_name',
        'idx_address_objects_name',
        'idx_address_objects_type_name',
        'idx_apartment_types_name',
        'idx_apartment_types_short_name',
        'idx_apartments_number',
        'idx_car_places_number',
        'idx_house_addr_types_name',
        'idx_house_addr_types_short_name',
        'idx_house_types_name',
        'idx_house_types_short_name',
        'idx_houses_add_num1',
        'idx_houses_add_num2',
        'idx_houses_house_num',
        'idx_normative_doc_kinds_name',
        'idx_normative_doc_types_name',
        'idx_normative_docs_name',
        'idx_normative_docs_number',
        'idx_normative_docs_orgname',
        'idx_object_levels_name',
        'idx_operation_types_name',
        'idx_param_types_descr',
        'idx_param_types_name',
        'idx_room_types_name',
        'idx_room_types_short_name',
        'idx_rooms_number',
        'idx_steads_number',
    ];

    private const GAR_UNIT_LIFECYCLE_INDEXES = [
        'idx_address_objects_end_date',
        'idx_address_objects_is_active',
        'idx_address_objects_is_actual',
        'idx_address_objects_start_date',
        'idx_address_objects_update_date',
        'idx_apartments_end_date',
        'idx_apartments_is_active',
        'idx_apartments_is_actual',
        'idx_apartments_start_date',
        'idx_apartments_update_date',
        'idx_car_places_end_date',
        'idx_car_places_is_active',
        'idx_car_places_is_actual',
        'idx_car_places_start_date',
        'idx_car_places_update_date',
        'idx_houses_end_date',
        'idx_houses_is_active',
        'idx_houses_is_actual',
        'idx_houses_start_date',
        'idx_houses_update_date',
        'idx_rooms_end_date',
        'idx_rooms_is_active',
        'idx_rooms_is_actual',
        'idx_rooms_start_date',
        'idx_rooms_update_date',
        'idx_steads_end_date',
        'idx_steads_is_active',
        'idx_steads_is_actual',
        'idx_steads_start_date',
        'idx_steads_update_date',
    ];

    private const GAR_VERSION_LINK_INDEXES = [
        'idx_address_objects_next_id',
        'idx_address_objects_prev_id',
        'idx_administrative_reestr_hierarchy_next_id',
        'idx_administrative_reestr_hierarchy_prev_id',
        'idx_apartments_next_id',
        'idx_apartments_prev_id',
        'idx_car_places_next_id',
        'idx_car_places_prev_id',
        'idx_houses_next_id',
        'idx_houses_prev_id',
        'idx_municipal_reestr_hierarchy_next_id',
        'idx_municipal_reestr_hierarchy_prev_id',
        'idx_rooms_next_id',
        'idx_rooms_prev_id',
        'idx_steads_next_id',
        'idx_steads_prev_id',
    ];

    private const OBJECT_PARAMS_LIFECYCLE_INDEXES = [
        'idx_object_params_end_date',
        'idx_object_params_start_date',
        'idx_object_params_update_date',
    ];

    private const GAR_UNIT_GUID_INDEXES = [
        'idx_address_objects_reestr_object_guid',
        'idx_apartments_reestr_object_guid',
        'idx_car_places_reestr_object_guid',
        'idx_houses_reestr_object_guid',
        'idx_rooms_reestr_object_guid',
        'idx_steads_object_guid',
    ];

    private const GAR_HIERARCHY_CROSS_LINK_INDEXES = [
        'idx_administrative_reestr_hierarchy_change_id',
        'idx_administrative_reestr_hierarchy_parent_obj_id',
        'idx_municipal_reestr_hierarchy_change_id',
        'idx_municipal_reestr_hierarchy_parent_obj_id',
    ];

    private const GAR_HIERARCHY_START_UPDATE_INDEXES = [
        'idx_administrative_reestr_hierarchy_start_date',
        'idx_administrative_reestr_hierarchy_update_date',
        'idx_municipal_reestr_hierarchy_start_date',
        'idx_municipal_reestr_hierarchy_update_date',
    ];

    private const CHANGE_HISTORY_UNUSED_INDEXES = [
        'idx_change_history_addr_object_guid',
        'idx_change_history_change_date',
        'idx_change_history_ndoc_id',
    ];

    private const REESTR_OBJECT_UNUSED_METADATA_INDEXES = [
        'idx_reestr_objects_change_id',
        'idx_reestr_objects_create_date',
        'idx_reestr_objects_is_active',
        'idx_reestr_objects_update_date',
    ];

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
                if (!\is_string($name)) {
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
    to_regclass('public.idx_object_params_value')::text AS raw_value_trgm_index,
    to_regclass('public.idx_administrative_reestr_hierarchy_path')::text AS adm_path_trgm_index,
    to_regclass('public.idx_administrative_reestr_hierarchy_path_arr')::text AS adm_path_array_index,
    to_regclass('public.idx_municipal_reestr_hierarchy_path')::text AS mun_path_trgm_index,
    to_regclass('public.idx_municipal_reestr_hierarchy_path_arr')::text AS mun_path_array_index,
    to_regclass('public.idx_address_search_region_hierarchy')::text AS region_index,
    to_regclass('public.gar_import_operations')::text AS import_operations,
    to_regclass('public.gar_import_files')::text AS import_files,
    to_regclass('public.gar_import_file_batches')::text AS import_file_batches,
    to_regclass('public.gar_import_foreign_key_snapshots')::text AS foreign_key_snapshots,
    (
        SELECT data_type
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'gar_import_operations'
          AND column_name = 'phase'
    ) AS import_phase_type,
    (
        SELECT data_type
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'gar_import_operations'
          AND column_name = 'write_mode'
    ) AS import_write_mode_type,
    (
        SELECT data_type
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'gar_import_operations'
          AND column_name = 'pipeline_file_key'
    ) AS pipeline_file_key_type,
    (
        SELECT data_type
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'gar_import_files'
          AND column_name = 'pipeline_enabled'
    ) AS pipeline_enabled_type,
    (
        SELECT data_type
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'gar_import_operations'
          AND column_name = 'defer_foreign_keys'
    ) AS defer_foreign_keys_type,
    (
        SELECT data_type
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'gar_import_operations'
          AND column_name = 'defer_primary_keys'
    ) AS defer_primary_keys_type,
    (
        SELECT data_type
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'gar_import_index_snapshots'
          AND column_name = 'constraint_definition'
    ) AS primary_snapshot_definition_type,
    (
        SELECT data_type
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'gar_import_index_snapshots'
          AND column_name = 'operation_id'
    ) AS snapshot_operation_type,
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
            Assert::same($schema['raw_value_trgm_index'] ?? null, null);
            Assert::null($schema['adm_path_trgm_index'] ?? null);
            Assert::null($schema['adm_path_array_index'] ?? null);
            Assert::null($schema['mun_path_trgm_index'] ?? null);
            Assert::null($schema['mun_path_array_index'] ?? null);
            Assert::same($schema['region_index'] ?? null, 'idx_address_search_region_hierarchy');
            Assert::same($schema['region_generated'] ?? null, 'NEVER');
            Assert::same($schema['import_operations'] ?? null, 'gar_import_operations');
            Assert::same($schema['import_files'] ?? null, 'gar_import_files');
            Assert::same($schema['import_file_batches'] ?? null, 'gar_import_file_batches');
            Assert::same(
                $schema['foreign_key_snapshots'] ?? null,
                'gar_import_foreign_key_snapshots',
            );
            Assert::same($schema['import_phase_type'] ?? null, 'character varying');
            Assert::same($schema['import_write_mode_type'] ?? null, 'character varying');
            Assert::same($schema['pipeline_file_key_type'] ?? null, 'character');
            Assert::same($schema['pipeline_enabled_type'] ?? null, 'boolean');
            Assert::same($schema['defer_foreign_keys_type'] ?? null, 'boolean');
            Assert::same($schema['defer_primary_keys_type'] ?? null, 'boolean');
            Assert::same($schema['primary_snapshot_definition_type'] ?? null, 'text');
            Assert::same($schema['snapshot_operation_type'] ?? null, 'bigint');
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
                if (!\is_string($extension)) {
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

    public function testItRestoresUnusedGarHierarchyPathIndexesOnRollback(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 1);

        try {
            IntegrationDatabase::resetSchema($pool);
            $targetMigration = \array_search(
                M261112010000DropUnusedGarHierarchyPathIndexes::class,
                MigrationHistoryContract::CLASSES,
                true,
            );
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', [
                '--limit' => $targetMigration,
                '--force-yes' => true,
            ]);

            Assert::same(self::garHierarchyPathIndexes($pool), [
                'idx_administrative_reestr_hierarchy_path',
                'idx_administrative_reestr_hierarchy_path_arr',
                'idx_municipal_reestr_hierarchy_path',
                'idx_municipal_reestr_hierarchy_path_arr',
            ]);

            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', [
                '--limit' => 1,
                '--force-yes' => true,
            ]);

            Assert::same(self::garHierarchyPathIndexes($pool), []);

            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:down', [
                '--limit' => 1,
                '--force-yes' => true,
            ]);

            Assert::same(self::garHierarchyPathIndexes($pool), [
                'idx_administrative_reestr_hierarchy_path',
                'idx_administrative_reestr_hierarchy_path_arr',
                'idx_municipal_reestr_hierarchy_path',
                'idx_municipal_reestr_hierarchy_path_arr',
            ]);

            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', [
                '--limit' => 1,
                '--force-yes' => true,
            ]);

            Assert::same(self::garHierarchyPathIndexes($pool), []);
        } finally {
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }

    public function testItRestoresUnusedGarTrigramIndexesOnRollback(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 1);

        try {
            IntegrationDatabase::resetSchema($pool);
            $targetMigration = \array_search(
                M261112020000DropUnusedGarTrigramIndexes::class,
                MigrationHistoryContract::CLASSES,
                true,
            );
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', [
                '--limit' => $targetMigration,
                '--force-yes' => true,
            ]);

            Assert::same(self::garTrigramIndexes($pool), self::GAR_TRIGRAM_INDEXES);

            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', [
                '--limit' => 1,
                '--force-yes' => true,
            ]);

            Assert::same(self::garTrigramIndexes($pool), []);

            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:down', [
                '--limit' => 1,
                '--force-yes' => true,
            ]);

            Assert::same(self::garTrigramIndexes($pool), self::GAR_TRIGRAM_INDEXES);

            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', [
                '--limit' => 1,
                '--force-yes' => true,
            ]);

            Assert::same(self::garTrigramIndexes($pool), []);
        } finally {
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }

    public function testItRestoresRedundantGarUnitLifecycleIndexesOnRollback(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 1);

        try {
            IntegrationDatabase::resetSchema($pool);
            $targetMigration = \array_search(
                M261112030000DropRedundantGarUnitLifecycleIndexes::class,
                MigrationHistoryContract::CLASSES,
                true,
            );
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', [
                '--limit' => $targetMigration,
                '--force-yes' => true,
            ]);

            Assert::same(self::garUnitLifecycleIndexes($pool), self::GAR_UNIT_LIFECYCLE_INDEXES);

            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', [
                '--limit' => 1,
                '--force-yes' => true,
            ]);

            Assert::same(self::garUnitLifecycleIndexes($pool), []);

            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:down', [
                '--limit' => 1,
                '--force-yes' => true,
            ]);

            Assert::same(self::garUnitLifecycleIndexes($pool), self::GAR_UNIT_LIFECYCLE_INDEXES);

            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', [
                '--limit' => 1,
                '--force-yes' => true,
            ]);

            Assert::same(self::garUnitLifecycleIndexes($pool), []);
        } finally {
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }

    public function testItRestoresDetachedGarVersionLinkIndexesOnRollback(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 1);

        try {
            IntegrationDatabase::resetSchema($pool);
            $targetMigration = \array_search(
                M261112040000DropDetachedGarVersionLinkIndexes::class,
                MigrationHistoryContract::CLASSES,
                true,
            );
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', [
                '--limit' => $targetMigration,
                '--force-yes' => true,
            ]);

            Assert::same(self::garVersionLinkIndexes($pool), self::GAR_VERSION_LINK_INDEXES);

            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', [
                '--limit' => 1,
                '--force-yes' => true,
            ]);

            Assert::same(self::garVersionLinkIndexes($pool), []);

            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:down', [
                '--limit' => 1,
                '--force-yes' => true,
            ]);

            Assert::same(self::garVersionLinkIndexes($pool), self::GAR_VERSION_LINK_INDEXES);

            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', [
                '--limit' => 1,
                '--force-yes' => true,
            ]);

            Assert::same(self::garVersionLinkIndexes($pool), []);
        } finally {
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }

    public function testItRestoresRedundantObjectParamsLifecycleIndexesOnRollback(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 1);

        try {
            IntegrationDatabase::resetSchema($pool);
            $targetMigration = \array_search(
                M261112050000DropRedundantObjectParamsLifecycleIndexes::class,
                MigrationHistoryContract::CLASSES,
                true,
            );
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', [
                '--limit' => $targetMigration,
                '--force-yes' => true,
            ]);

            Assert::same(self::objectParamsLifecycleIndexes($pool), self::OBJECT_PARAMS_LIFECYCLE_INDEXES);

            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', [
                '--limit' => 1,
                '--force-yes' => true,
            ]);

            Assert::same(self::objectParamsLifecycleIndexes($pool), []);

            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:down', [
                '--limit' => 1,
                '--force-yes' => true,
            ]);

            Assert::same(self::objectParamsLifecycleIndexes($pool), self::OBJECT_PARAMS_LIFECYCLE_INDEXES);

            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', [
                '--limit' => 1,
                '--force-yes' => true,
            ]);

            Assert::same(self::objectParamsLifecycleIndexes($pool), []);
        } finally {
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }

    public function testItRestoresUnusedObjectParamsChangeEndIndexOnRollback(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 1);

        try {
            IntegrationDatabase::resetSchema($pool);
            $targetMigration = \array_search(
                M261112060000DropUnusedObjectParamsChangeEndIndex::class,
                MigrationHistoryContract::CLASSES,
                true,
            );
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', [
                '--limit' => $targetMigration,
                '--force-yes' => true,
            ]);

            Assert::same(self::objectParamsChangeEndIndex($pool), 'idx_object_params_change_id_end');

            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', [
                '--limit' => 1,
                '--force-yes' => true,
            ]);

            Assert::null(self::objectParamsChangeEndIndex($pool));

            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:down', [
                '--limit' => 1,
                '--force-yes' => true,
            ]);

            Assert::same(self::objectParamsChangeEndIndex($pool), 'idx_object_params_change_id_end');

            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', [
                '--limit' => 1,
                '--force-yes' => true,
            ]);

            Assert::null(self::objectParamsChangeEndIndex($pool));
        } finally {
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }

    public function testItRestoresUnusedGarUnitGuidIndexesOnRollback(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 1);

        try {
            IntegrationDatabase::resetSchema($pool);
            $targetMigration = \array_search(
                M261112070000DropUnusedGarUnitGuidIndexes::class,
                MigrationHistoryContract::CLASSES,
                true,
            );
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', [
                '--limit' => $targetMigration,
                '--force-yes' => true,
            ]);

            Assert::same(self::garUnitGuidIndexes($pool), self::GAR_UNIT_GUID_INDEXES);

            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', [
                '--limit' => 1,
                '--force-yes' => true,
            ]);

            Assert::same(self::garUnitGuidIndexes($pool), []);

            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:down', [
                '--limit' => 1,
                '--force-yes' => true,
            ]);

            Assert::same(self::garUnitGuidIndexes($pool), self::GAR_UNIT_GUID_INDEXES);

            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', [
                '--limit' => 1,
                '--force-yes' => true,
            ]);

            Assert::same(self::garUnitGuidIndexes($pool), []);
        } finally {
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }

    public function testItRestoresDetachedGarHierarchyCrossLinkIndexesOnRollback(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 1);

        try {
            IntegrationDatabase::resetSchema($pool);
            $targetMigration = \array_search(
                M261112080000DropDetachedGarHierarchyCrossLinkIndexes::class,
                MigrationHistoryContract::CLASSES,
                true,
            );
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', [
                '--limit' => $targetMigration,
                '--force-yes' => true,
            ]);

            Assert::same(self::garHierarchyCrossLinkIndexes($pool), self::GAR_HIERARCHY_CROSS_LINK_INDEXES);

            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', [
                '--limit' => 1,
                '--force-yes' => true,
            ]);

            Assert::same(self::garHierarchyCrossLinkIndexes($pool), []);

            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:down', [
                '--limit' => 1,
                '--force-yes' => true,
            ]);

            Assert::same(self::garHierarchyCrossLinkIndexes($pool), self::GAR_HIERARCHY_CROSS_LINK_INDEXES);

            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', [
                '--limit' => 1,
                '--force-yes' => true,
            ]);

            Assert::same(self::garHierarchyCrossLinkIndexes($pool), []);
        } finally {
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }

    public function testItRestoresUnusedGarHierarchyStartUpdateIndexesOnRollback(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 1);

        try {
            IntegrationDatabase::resetSchema($pool);
            $targetMigration = \array_search(
                M261113000000DropUnusedGarHierarchyStartUpdateIndexes::class,
                MigrationHistoryContract::CLASSES,
                true,
            );
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', [
                '--limit' => $targetMigration,
                '--force-yes' => true,
            ]);

            Assert::same(self::garHierarchyStartUpdateIndexes($pool), self::GAR_HIERARCHY_START_UPDATE_INDEXES);

            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', [
                '--limit' => 1,
                '--force-yes' => true,
            ]);

            Assert::same(self::garHierarchyStartUpdateIndexes($pool), []);

            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:down', [
                '--limit' => 1,
                '--force-yes' => true,
            ]);

            Assert::same(self::garHierarchyStartUpdateIndexes($pool), self::GAR_HIERARCHY_START_UPDATE_INDEXES);

            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', [
                '--limit' => 1,
                '--force-yes' => true,
            ]);

            Assert::same(self::garHierarchyStartUpdateIndexes($pool), []);
        } finally {
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }

    public function testItRestoresUnusedChangeHistoryIndexesOnRollback(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 1);

        try {
            IntegrationDatabase::resetSchema($pool);
            $targetMigration = \array_search(
                M261113010000DropUnusedChangeHistoryIndexes::class,
                MigrationHistoryContract::CLASSES,
                true,
            );
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', [
                '--limit' => $targetMigration,
                '--force-yes' => true,
            ]);

            Assert::same(self::unusedChangeHistoryIndexes($pool), self::CHANGE_HISTORY_UNUSED_INDEXES);

            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', [
                '--limit' => 1,
                '--force-yes' => true,
            ]);

            Assert::same(self::unusedChangeHistoryIndexes($pool), []);

            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:down', [
                '--limit' => 1,
                '--force-yes' => true,
            ]);

            Assert::same(self::unusedChangeHistoryIndexes($pool), self::CHANGE_HISTORY_UNUSED_INDEXES);

            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', [
                '--limit' => 1,
                '--force-yes' => true,
            ]);

            Assert::same(self::unusedChangeHistoryIndexes($pool), []);
        } finally {
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }

    public function testItRestoresUnusedReestrObjectMetadataIndexesOnRollback(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 1);

        try {
            IntegrationDatabase::resetSchema($pool);
            $targetMigration = \array_search(
                M261113020000DropUnusedReestrObjectMetadataIndexes::class,
                MigrationHistoryContract::CLASSES,
                true,
            );
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', [
                '--limit' => $targetMigration,
                '--force-yes' => true,
            ]);

            Assert::same(
                self::unusedReestrObjectMetadataIndexes($pool),
                self::REESTR_OBJECT_UNUSED_METADATA_INDEXES,
            );

            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', [
                '--limit' => 1,
                '--force-yes' => true,
            ]);

            Assert::same(self::unusedReestrObjectMetadataIndexes($pool), []);

            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:down', [
                '--limit' => 1,
                '--force-yes' => true,
            ]);

            Assert::same(
                self::unusedReestrObjectMetadataIndexes($pool),
                self::REESTR_OBJECT_UNUSED_METADATA_INDEXES,
            );

            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', [
                '--limit' => 1,
                '--force-yes' => true,
            ]);

            Assert::same(self::unusedReestrObjectMetadataIndexes($pool), []);
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
            $targetMigration = \array_search(
                M261104040000UseGarRegionCodeForAddressSearch::class,
                MigrationHistoryContract::CLASSES,
                true,
            );
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', [
                '--limit' => $targetMigration,
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

    /** @return list<string> */
    private static function garHierarchyPathIndexes(PostgresConnectionPool $pool): array
    {
        $indexes = [];
        $result = $pool->query(<<<'SQL'
SELECT indexname
FROM pg_indexes
WHERE schemaname = 'public'
  AND indexname IN (
      'idx_administrative_reestr_hierarchy_path',
      'idx_administrative_reestr_hierarchy_path_arr',
      'idx_municipal_reestr_hierarchy_path',
      'idx_municipal_reestr_hierarchy_path_arr'
  )
ORDER BY indexname
SQL);
        while (($row = $result->fetchRow()) !== null) {
            $index = $row['indexname'] ?? null;
            if (!\is_string($index)) {
                throw new RuntimeException('PostgreSQL returned an invalid index name.');
            }
            $indexes[] = $index;
        }

        return $indexes;
    }

    /** @return list<string> */
    private static function garTrigramIndexes(PostgresConnectionPool $pool): array
    {
        $indexes = [];
        $result = $pool->query(<<<'SQL'
SELECT indexname
FROM pg_indexes
WHERE schemaname = 'public'
  AND indexname IN (
      'idx_address_object_types_name',
      'idx_address_object_types_short_name',
      'idx_address_objects_name',
      'idx_address_objects_type_name',
      'idx_apartment_types_name',
      'idx_apartment_types_short_name',
      'idx_apartments_number',
      'idx_car_places_number',
      'idx_house_addr_types_name',
      'idx_house_addr_types_short_name',
      'idx_house_types_name',
      'idx_house_types_short_name',
      'idx_houses_add_num1',
      'idx_houses_add_num2',
      'idx_houses_house_num',
      'idx_normative_doc_kinds_name',
      'idx_normative_doc_types_name',
      'idx_normative_docs_name',
      'idx_normative_docs_number',
      'idx_normative_docs_orgname',
      'idx_object_levels_name',
      'idx_operation_types_name',
      'idx_param_types_descr',
      'idx_param_types_name',
      'idx_room_types_name',
      'idx_room_types_short_name',
      'idx_rooms_number',
      'idx_steads_number'
  )
ORDER BY indexname
SQL);
        while (($row = $result->fetchRow()) !== null) {
            $index = $row['indexname'] ?? null;
            if (!\is_string($index)) {
                throw new RuntimeException('PostgreSQL returned an invalid index name.');
            }
            $indexes[] = $index;
        }

        return $indexes;
    }

    /** @return list<string> */
    private static function garUnitLifecycleIndexes(PostgresConnectionPool $pool): array
    {
        $indexes = [];
        $result = $pool->query(<<<'SQL'
SELECT indexname
FROM pg_indexes
WHERE schemaname = 'public'
  AND indexname ~ '^idx_(address_objects|apartments|car_places|houses|rooms|steads)_(end_date|is_active|is_actual|start_date|update_date)$'
ORDER BY indexname
SQL);
        while (($row = $result->fetchRow()) !== null) {
            $index = $row['indexname'] ?? null;
            if (!\is_string($index)) {
                throw new RuntimeException('PostgreSQL returned an invalid index name.');
            }
            $indexes[] = $index;
        }

        return $indexes;
    }

    /** @return list<string> */
    private static function garVersionLinkIndexes(PostgresConnectionPool $pool): array
    {
        $indexes = [];
        $result = $pool->query(<<<'SQL'
SELECT indexname
FROM pg_indexes
WHERE schemaname = 'public'
  AND indexname ~ '^idx_(address_objects|administrative_reestr_hierarchy|apartments|car_places|houses|municipal_reestr_hierarchy|rooms|steads)_(next_id|prev_id)$'
ORDER BY indexname
SQL);
        while (($row = $result->fetchRow()) !== null) {
            $index = $row['indexname'] ?? null;
            if (!\is_string($index)) {
                throw new RuntimeException('PostgreSQL returned an invalid index name.');
            }
            $indexes[] = $index;
        }

        return $indexes;
    }

    /** @return list<string> */
    private static function objectParamsLifecycleIndexes(PostgresConnectionPool $pool): array
    {
        $indexes = [];
        $result = $pool->query(<<<'SQL'
SELECT indexname
FROM pg_indexes
WHERE schemaname = 'public'
  AND indexname ~ '^idx_object_params_(end_date|start_date|update_date)$'
ORDER BY indexname
SQL);
        while (($row = $result->fetchRow()) !== null) {
            $index = $row['indexname'] ?? null;
            if (!\is_string($index)) {
                throw new RuntimeException('PostgreSQL returned an invalid index name.');
            }
            $indexes[] = $index;
        }

        return $indexes;
    }

    private static function objectParamsChangeEndIndex(PostgresConnectionPool $pool): ?string
    {
        $row = $pool->query(
            "SELECT to_regclass('public.idx_object_params_change_id_end')::text AS index_name",
        )->fetchRow();
        Assert::notNull($row);
        $index = $row['index_name'] ?? null;

        return \is_string($index) ? $index : null;
    }

    /** @return list<string> */
    private static function garUnitGuidIndexes(PostgresConnectionPool $pool): array
    {
        $indexes = [];
        $result = $pool->query(<<<'SQL'
SELECT indexname
FROM pg_indexes
WHERE schemaname = 'public'
  AND indexname IN (
      'idx_address_objects_reestr_object_guid',
      'idx_apartments_reestr_object_guid',
      'idx_car_places_reestr_object_guid',
      'idx_houses_reestr_object_guid',
      'idx_rooms_reestr_object_guid',
      'idx_steads_object_guid'
  )
ORDER BY indexname
SQL);
        while (($row = $result->fetchRow()) !== null) {
            $index = $row['indexname'] ?? null;
            if (!\is_string($index)) {
                throw new RuntimeException('PostgreSQL returned an invalid index name.');
            }
            $indexes[] = $index;
        }

        return $indexes;
    }

    /** @return list<string> */
    private static function garHierarchyCrossLinkIndexes(PostgresConnectionPool $pool): array
    {
        $indexes = [];
        $result = $pool->query(<<<'SQL'
SELECT indexname
FROM pg_indexes
WHERE schemaname = 'public'
  AND indexname IN (
      'idx_administrative_reestr_hierarchy_change_id',
      'idx_administrative_reestr_hierarchy_parent_obj_id',
      'idx_municipal_reestr_hierarchy_change_id',
      'idx_municipal_reestr_hierarchy_parent_obj_id'
  )
ORDER BY indexname
SQL);
        while (($row = $result->fetchRow()) !== null) {
            $index = $row['indexname'] ?? null;
            if (!\is_string($index)) {
                throw new RuntimeException('PostgreSQL returned an invalid index name.');
            }
            $indexes[] = $index;
        }

        return $indexes;
    }

    /** @return list<string> */
    private static function garHierarchyStartUpdateIndexes(PostgresConnectionPool $pool): array
    {
        $indexes = [];
        $result = $pool->query(<<<'SQL'
SELECT indexname
FROM pg_indexes
WHERE schemaname = 'public'
  AND indexname IN (
      'idx_administrative_reestr_hierarchy_start_date',
      'idx_administrative_reestr_hierarchy_update_date',
      'idx_municipal_reestr_hierarchy_start_date',
      'idx_municipal_reestr_hierarchy_update_date'
  )
ORDER BY indexname
SQL);
        while (($row = $result->fetchRow()) !== null) {
            $index = $row['indexname'] ?? null;
            if (!\is_string($index)) {
                throw new RuntimeException('PostgreSQL returned an invalid index name.');
            }
            $indexes[] = $index;
        }

        return $indexes;
    }

    /** @return list<string> */
    private static function unusedChangeHistoryIndexes(PostgresConnectionPool $pool): array
    {
        $indexes = [];
        $result = $pool->query(<<<'SQL'
SELECT indexname
FROM pg_indexes
WHERE schemaname = 'public'
  AND indexname IN (
      'idx_change_history_addr_object_guid',
      'idx_change_history_change_date',
      'idx_change_history_ndoc_id'
  )
ORDER BY indexname
SQL);
        while (($row = $result->fetchRow()) !== null) {
            $index = $row['indexname'] ?? null;
            if (!\is_string($index)) {
                throw new RuntimeException('PostgreSQL returned an invalid index name.');
            }
            $indexes[] = $index;
        }

        return $indexes;
    }

    /** @return list<string> */
    private static function unusedReestrObjectMetadataIndexes(PostgresConnectionPool $pool): array
    {
        $indexes = [];
        $result = $pool->query(<<<'SQL'
SELECT indexname
FROM pg_indexes
WHERE schemaname = 'public'
  AND indexname IN (
      'idx_reestr_objects_change_id',
      'idx_reestr_objects_create_date',
      'idx_reestr_objects_is_active',
      'idx_reestr_objects_update_date'
  )
ORDER BY indexname
SQL);
        while (($row = $result->fetchRow()) !== null) {
            $index = $row['indexname'] ?? null;
            if (!\is_string($index)) {
                throw new RuntimeException('PostgreSQL returned an invalid index name.');
            }
            $indexes[] = $index;
        }

        return $indexes;
    }
}
