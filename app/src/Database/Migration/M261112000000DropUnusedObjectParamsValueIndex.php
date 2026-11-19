<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

final class M261112000000DropUnusedObjectParamsValueIndex implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute('DROP INDEX CONCURRENTLY IF EXISTS idx_object_params_value');
    }

    public function down(MigrationBuilder $b): void
    {
        $b->execute(
            <<<'SQL'
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_object_params_value
    ON object_params USING GIN (value gin_trgm_ops)
SQL,
        );
    }
}
