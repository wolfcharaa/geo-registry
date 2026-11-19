<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

final class M261112060000DropUnusedObjectParamsChangeEndIndex implements RevertibleMigrationInterface
{
    private const INDEX = 'idx_object_params_change_id_end';

    public function up(MigrationBuilder $b): void
    {
        $b->execute('DROP INDEX CONCURRENTLY IF EXISTS ' . self::INDEX);
    }

    public function down(MigrationBuilder $b): void
    {
        $b->execute(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_object_params_change_id_end '
            . 'ON object_params USING BTREE (change_id_end)',
        );
    }
}
