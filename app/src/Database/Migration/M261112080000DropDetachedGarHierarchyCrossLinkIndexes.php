<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

final class M261112080000DropDetachedGarHierarchyCrossLinkIndexes implements RevertibleMigrationInterface
{
    /**
     * @var array<string, string>
     */
    private const INDEXES = [
        'idx_administrative_reestr_hierarchy_change_id' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_administrative_reestr_hierarchy_change_id ON administrative_reestr_hierarchy USING BTREE (change_id)',
        'idx_administrative_reestr_hierarchy_parent_obj_id' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_administrative_reestr_hierarchy_parent_obj_id ON administrative_reestr_hierarchy USING BTREE (parent_obj_id)',
        'idx_municipal_reestr_hierarchy_change_id' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_municipal_reestr_hierarchy_change_id ON municipal_reestr_hierarchy USING BTREE (change_id)',
        'idx_municipal_reestr_hierarchy_parent_obj_id' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_municipal_reestr_hierarchy_parent_obj_id ON municipal_reestr_hierarchy USING BTREE (parent_obj_id)',
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
