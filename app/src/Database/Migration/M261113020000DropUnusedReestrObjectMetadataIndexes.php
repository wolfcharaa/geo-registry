<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

final class M261113020000DropUnusedReestrObjectMetadataIndexes implements RevertibleMigrationInterface
{
    /**
     * @var array<string, string>
     */
    private const INDEXES = [
        'idx_reestr_objects_change_id' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_reestr_objects_change_id ON reestr_objects USING BTREE (change_id)',
        'idx_reestr_objects_create_date' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_reestr_objects_create_date ON reestr_objects USING BTREE (create_date)',
        'idx_reestr_objects_is_active' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_reestr_objects_is_active ON reestr_objects USING BTREE (is_active)',
        'idx_reestr_objects_update_date' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_reestr_objects_update_date ON reestr_objects USING BTREE (update_date)',
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
