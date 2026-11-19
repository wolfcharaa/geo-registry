<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

final class M261113010000DropUnusedChangeHistoryIndexes implements RevertibleMigrationInterface
{
    /**
     * @var array<string, string>
     */
    private const INDEXES = [
        'idx_change_history_addr_object_guid' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_change_history_addr_object_guid ON change_history USING BTREE (addr_object_guid)',
        'idx_change_history_change_date' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_change_history_change_date ON change_history USING BTREE (change_date)',
        'idx_change_history_ndoc_id' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_change_history_ndoc_id ON change_history USING BTREE (ndoc_id)',
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
