<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

final class M261112020000DropUnusedGarTrigramIndexes implements RevertibleMigrationInterface
{
    /**
     * @var array<string, string>
     */
    private const INDEXES = [
        'idx_address_object_types_name' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_address_object_types_name ON address_object_types USING GIN (name gin_trgm_ops)',
        'idx_address_object_types_short_name' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_address_object_types_short_name ON address_object_types USING GIN (short_name gin_trgm_ops)',
        'idx_address_objects_name' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_address_objects_name ON address_objects USING GIN (name gin_trgm_ops)',
        'idx_address_objects_type_name' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_address_objects_type_name ON address_objects USING GIN (type_name gin_trgm_ops)',
        'idx_apartment_types_name' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_apartment_types_name ON apartment_types USING GIN (name gin_trgm_ops)',
        'idx_apartment_types_short_name' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_apartment_types_short_name ON apartment_types USING GIN (short_name gin_trgm_ops)',
        'idx_apartments_number' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_apartments_number ON apartments USING GIN (number gin_trgm_ops)',
        'idx_car_places_number' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_car_places_number ON car_places USING GIN (number gin_trgm_ops)',
        'idx_house_addr_types_name' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_house_addr_types_name ON house_addr_types USING GIN (name gin_trgm_ops)',
        'idx_house_addr_types_short_name' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_house_addr_types_short_name ON house_addr_types USING GIN (short_name gin_trgm_ops)',
        'idx_house_types_name' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_house_types_name ON house_types USING GIN (name gin_trgm_ops)',
        'idx_house_types_short_name' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_house_types_short_name ON house_types USING GIN (short_name gin_trgm_ops)',
        'idx_houses_add_num1' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_houses_add_num1 ON houses USING GIN (add_num1 gin_trgm_ops)',
        'idx_houses_add_num2' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_houses_add_num2 ON houses USING GIN (add_num2 gin_trgm_ops)',
        'idx_houses_house_num' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_houses_house_num ON houses USING GIN (house_num gin_trgm_ops)',
        'idx_normative_doc_kinds_name' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_normative_doc_kinds_name ON normative_doc_kinds USING GIN (name gin_trgm_ops)',
        'idx_normative_doc_types_name' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_normative_doc_types_name ON normative_doc_types USING GIN (name gin_trgm_ops)',
        'idx_normative_docs_name' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_normative_docs_name ON normative_docs USING GIN (name gin_trgm_ops)',
        'idx_normative_docs_number' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_normative_docs_number ON normative_docs USING GIN (number gin_trgm_ops)',
        'idx_normative_docs_orgname' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_normative_docs_orgname ON normative_docs USING GIN (orgname gin_trgm_ops)',
        'idx_object_levels_name' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_object_levels_name ON object_levels USING GIN (name gin_trgm_ops)',
        'idx_operation_types_name' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_operation_types_name ON operation_types USING GIN (name gin_trgm_ops)',
        'idx_param_types_descr' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_param_types_descr ON param_types USING GIN (descr gin_trgm_ops)',
        'idx_param_types_name' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_param_types_name ON param_types USING GIN (name gin_trgm_ops)',
        'idx_room_types_name' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_room_types_name ON room_types USING GIN (name gin_trgm_ops)',
        'idx_room_types_short_name' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_room_types_short_name ON room_types USING GIN (short_name gin_trgm_ops)',
        'idx_rooms_number' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_rooms_number ON rooms USING GIN (number gin_trgm_ops)',
        'idx_steads_number' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_steads_number ON steads USING GIN (number gin_trgm_ops)',
    ];

    public function up(MigrationBuilder $b): void
    {
        foreach (\array_keys(self::INDEXES) as $index) {
            $b->execute("DROP INDEX CONCURRENTLY IF EXISTS {$index}");
        }
    }

    public function down(MigrationBuilder $b): void
    {
        foreach (self::INDEXES as $sql) {
            $b->execute($sql);
        }
    }
}
