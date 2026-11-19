<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

final class M261112070000DropUnusedGarUnitGuidIndexes implements RevertibleMigrationInterface
{
    /**
     * @var array<string, string>
     */
    private const INDEXES = [
        'idx_address_objects_reestr_object_guid' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_address_objects_reestr_object_guid ON address_objects USING BTREE (reestr_object_guid)',
        'idx_apartments_reestr_object_guid' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_apartments_reestr_object_guid ON apartments USING BTREE (reestr_object_guid)',
        'idx_car_places_reestr_object_guid' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_car_places_reestr_object_guid ON car_places USING BTREE (reestr_object_guid)',
        'idx_houses_reestr_object_guid' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_houses_reestr_object_guid ON houses USING BTREE (reestr_object_guid)',
        'idx_rooms_reestr_object_guid' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_rooms_reestr_object_guid ON rooms USING BTREE (reestr_object_guid)',
        'idx_steads_object_guid' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_steads_object_guid ON steads USING BTREE (object_guid)',
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
