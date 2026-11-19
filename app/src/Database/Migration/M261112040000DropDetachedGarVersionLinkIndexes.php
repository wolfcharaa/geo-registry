<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

final class M261112040000DropDetachedGarVersionLinkIndexes implements RevertibleMigrationInterface
{
    /**
     * @var array<string, string>
     */
    private const INDEXES = [
        'idx_address_objects_next_id' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_address_objects_next_id ON address_objects USING BTREE (next_id)',
        'idx_address_objects_prev_id' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_address_objects_prev_id ON address_objects USING BTREE (prev_id)',
        'idx_administrative_reestr_hierarchy_next_id' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_administrative_reestr_hierarchy_next_id ON administrative_reestr_hierarchy USING BTREE (next_id)',
        'idx_administrative_reestr_hierarchy_prev_id' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_administrative_reestr_hierarchy_prev_id ON administrative_reestr_hierarchy USING BTREE (prev_id)',
        'idx_apartments_next_id' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_apartments_next_id ON apartments USING BTREE (next_id)',
        'idx_apartments_prev_id' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_apartments_prev_id ON apartments USING BTREE (prev_id)',
        'idx_car_places_next_id' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_car_places_next_id ON car_places USING BTREE (next_id)',
        'idx_car_places_prev_id' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_car_places_prev_id ON car_places USING BTREE (prev_id)',
        'idx_houses_next_id' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_houses_next_id ON houses USING BTREE (next_id)',
        'idx_houses_prev_id' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_houses_prev_id ON houses USING BTREE (prev_id)',
        'idx_municipal_reestr_hierarchy_next_id' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_municipal_reestr_hierarchy_next_id ON municipal_reestr_hierarchy USING BTREE (next_id)',
        'idx_municipal_reestr_hierarchy_prev_id' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_municipal_reestr_hierarchy_prev_id ON municipal_reestr_hierarchy USING BTREE (prev_id)',
        'idx_rooms_next_id' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_rooms_next_id ON rooms USING BTREE (next_id)',
        'idx_rooms_prev_id' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_rooms_prev_id ON rooms USING BTREE (prev_id)',
        'idx_steads_next_id' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_steads_next_id ON steads USING BTREE (next_id)',
        'idx_steads_prev_id' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_steads_prev_id ON steads USING BTREE (prev_id)',
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
