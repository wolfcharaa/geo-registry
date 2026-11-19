<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

final class M261117000000AddGarPrimaryKeyLifecycle implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute(<<<'SQL'
ALTER TABLE gar_import_operations
    ADD COLUMN defer_primary_keys boolean NOT NULL DEFAULT false,
    ADD CONSTRAINT chk_gar_import_operations_primary_key_mode
        CHECK (
            NOT defer_primary_keys
            OR (
                mode = 'initial-load'
                AND write_mode = 'direct-copy'
                AND defer_foreign_keys
            )
        )
SQL);
        $b->execute(<<<'SQL'
ALTER TABLE gar_import_index_snapshots
    ADD COLUMN constraint_name text,
    ADD COLUMN constraint_definition text,
    ADD COLUMN constraint_definition_hash char(64),
    ADD CONSTRAINT chk_gar_import_index_snapshots_primary_key
        CHECK (
            (constraint_name IS NULL
                AND constraint_definition IS NULL
                AND constraint_definition_hash IS NULL)
            OR
            (constraint_name IS NOT NULL
                AND constraint_definition IS NOT NULL
                AND constraint_definition_hash IS NOT NULL)
        )
SQL);
        $b->execute(
            "COMMENT ON COLUMN gar_import_operations.defer_primary_keys IS 'Direct initial-load snapshot/drop/bulk-restore lifecycle для canonical primary keys'",
        );
        $b->execute(
            "COMMENT ON COLUMN gar_import_index_snapshots.constraint_name IS 'Primary-key constraint restored from the rebuilt unique index; NULL for secondary indexes'",
        );
        $b->execute(
            "COMMENT ON COLUMN gar_import_index_snapshots.constraint_definition IS 'Original pg_get_constraintdef for fail-closed primary-key verification'",
        );
    }

    public function down(MigrationBuilder $b): void
    {
        $b->execute(<<<'SQL'
ALTER TABLE gar_import_index_snapshots
    DROP CONSTRAINT IF EXISTS chk_gar_import_index_snapshots_primary_key,
    DROP COLUMN IF EXISTS constraint_definition_hash,
    DROP COLUMN IF EXISTS constraint_definition,
    DROP COLUMN IF EXISTS constraint_name
SQL);
        $b->execute(<<<'SQL'
ALTER TABLE gar_import_operations
    DROP CONSTRAINT IF EXISTS chk_gar_import_operations_primary_key_mode,
    DROP COLUMN IF EXISTS defer_primary_keys
SQL);
    }
}
