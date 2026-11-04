<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

final class M260903130000RebuildObjectParamsDenormIndex implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute('DROP INDEX CONCURRENTLY IF EXISTS idx_object_params_denorm_type_object_update');
        $b->execute(
            <<<'SQL'
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_object_params_denorm_type_object_update
    ON object_params (type_id, object_id, update_date DESC, id DESC)
    WHERE type_id IN (5, 6, 7, 16)
SQL,
        );
    }

    public function down(MigrationBuilder $b): void
    {
        $b->execute('DROP INDEX CONCURRENTLY IF EXISTS idx_object_params_denorm_type_object_update');
        $b->execute(
            <<<'SQL'
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_object_params_denorm_type_object_update
    ON object_params (type_id, object_id, update_date DESC, id DESC)
    WHERE type_id IN (5, 16)
SQL,
        );
    }
}
