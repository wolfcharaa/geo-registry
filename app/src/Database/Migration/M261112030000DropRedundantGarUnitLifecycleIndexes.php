<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

final class M261112030000DropRedundantGarUnitLifecycleIndexes implements RevertibleMigrationInterface
{
    /**
     * @var array<string, string>
     */
    private const INDEXES = [
        'idx_address_objects_end_date' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_address_objects_end_date ON address_objects USING BTREE (end_date)',
        'idx_address_objects_is_active' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_address_objects_is_active ON address_objects USING BTREE (is_active)',
        'idx_address_objects_is_actual' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_address_objects_is_actual ON address_objects USING BTREE (is_actual)',
        'idx_address_objects_start_date' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_address_objects_start_date ON address_objects USING BTREE (start_date)',
        'idx_address_objects_update_date' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_address_objects_update_date ON address_objects USING BTREE (update_date)',
        'idx_apartments_end_date' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_apartments_end_date ON apartments USING BTREE (end_date)',
        'idx_apartments_is_active' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_apartments_is_active ON apartments USING BTREE (is_active)',
        'idx_apartments_is_actual' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_apartments_is_actual ON apartments USING BTREE (is_actual)',
        'idx_apartments_start_date' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_apartments_start_date ON apartments USING BTREE (start_date)',
        'idx_apartments_update_date' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_apartments_update_date ON apartments USING BTREE (update_date)',
        'idx_car_places_end_date' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_car_places_end_date ON car_places USING BTREE (end_date)',
        'idx_car_places_is_active' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_car_places_is_active ON car_places USING BTREE (is_active)',
        'idx_car_places_is_actual' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_car_places_is_actual ON car_places USING BTREE (is_actual)',
        'idx_car_places_start_date' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_car_places_start_date ON car_places USING BTREE (start_date)',
        'idx_car_places_update_date' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_car_places_update_date ON car_places USING BTREE (update_date)',
        'idx_houses_end_date' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_houses_end_date ON houses USING BTREE (end_date)',
        'idx_houses_is_active' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_houses_is_active ON houses USING BTREE (is_active)',
        'idx_houses_is_actual' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_houses_is_actual ON houses USING BTREE (is_actual)',
        'idx_houses_start_date' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_houses_start_date ON houses USING BTREE (start_date)',
        'idx_houses_update_date' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_houses_update_date ON houses USING BTREE (update_date)',
        'idx_rooms_end_date' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_rooms_end_date ON rooms USING BTREE (end_date)',
        'idx_rooms_is_active' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_rooms_is_active ON rooms USING BTREE (is_active)',
        'idx_rooms_is_actual' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_rooms_is_actual ON rooms USING BTREE (is_actual)',
        'idx_rooms_start_date' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_rooms_start_date ON rooms USING BTREE (start_date)',
        'idx_rooms_update_date' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_rooms_update_date ON rooms USING BTREE (update_date)',
        'idx_steads_end_date' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_steads_end_date ON steads USING BTREE (end_date)',
        'idx_steads_is_active' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_steads_is_active ON steads USING BTREE (is_active)',
        'idx_steads_is_actual' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_steads_is_actual ON steads USING BTREE (is_actual)',
        'idx_steads_start_date' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_steads_start_date ON steads USING BTREE (start_date)',
        'idx_steads_update_date' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_steads_update_date ON steads USING BTREE (update_date)',
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
