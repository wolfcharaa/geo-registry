<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

final class M261113000000DropUnusedGarHierarchyStartUpdateIndexes implements RevertibleMigrationInterface
{
    /**
     * @var array<string, string>
     */
    private const INDEXES = [
        'idx_administrative_reestr_hierarchy_start_date' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_administrative_reestr_hierarchy_start_date ON administrative_reestr_hierarchy USING BTREE (start_date)',
        'idx_administrative_reestr_hierarchy_update_date' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_administrative_reestr_hierarchy_update_date ON administrative_reestr_hierarchy USING BTREE (update_date)',
        'idx_municipal_reestr_hierarchy_start_date' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_municipal_reestr_hierarchy_start_date ON municipal_reestr_hierarchy USING BTREE (start_date)',
        'idx_municipal_reestr_hierarchy_update_date' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_municipal_reestr_hierarchy_update_date ON municipal_reestr_hierarchy USING BTREE (update_date)',
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
