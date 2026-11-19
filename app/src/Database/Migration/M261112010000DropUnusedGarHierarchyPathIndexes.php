<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

final class M261112010000DropUnusedGarHierarchyPathIndexes implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute(
            'DROP INDEX CONCURRENTLY IF EXISTS idx_administrative_reestr_hierarchy_path',
        );
        $b->execute(
            'DROP INDEX CONCURRENTLY IF EXISTS idx_administrative_reestr_hierarchy_path_arr',
        );
        $b->execute(
            'DROP INDEX CONCURRENTLY IF EXISTS idx_municipal_reestr_hierarchy_path',
        );
        $b->execute(
            'DROP INDEX CONCURRENTLY IF EXISTS idx_municipal_reestr_hierarchy_path_arr',
        );
    }

    public function down(MigrationBuilder $b): void
    {
        $b->execute(
            <<<'SQL'
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_administrative_reestr_hierarchy_path
    ON administrative_reestr_hierarchy USING GIN (path gin_trgm_ops)
SQL,
        );
        $b->execute(
            <<<'SQL'
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_administrative_reestr_hierarchy_path_arr
    ON administrative_reestr_hierarchy USING GIN (path_arr)
SQL,
        );
        $b->execute(
            <<<'SQL'
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_municipal_reestr_hierarchy_path
    ON municipal_reestr_hierarchy USING GIN (path gin_trgm_ops)
SQL,
        );
        $b->execute(
            <<<'SQL'
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_municipal_reestr_hierarchy_path_arr
    ON municipal_reestr_hierarchy USING GIN (path_arr)
SQL,
        );
    }
}
