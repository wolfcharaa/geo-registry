<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

final class M261112050000DropRedundantObjectParamsLifecycleIndexes implements RevertibleMigrationInterface
{
    /**
     * @var array<string, string>
     */
    private const INDEXES = [
        'idx_object_params_end_date' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_object_params_end_date ON object_params USING BTREE (end_date)',
        'idx_object_params_start_date' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_object_params_start_date ON object_params USING BTREE (start_date)',
        'idx_object_params_update_date' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_object_params_update_date ON object_params USING BTREE (update_date)',
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
