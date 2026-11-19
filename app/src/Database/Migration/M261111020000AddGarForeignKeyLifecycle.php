<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

final class M261111020000AddGarForeignKeyLifecycle implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute(<<<'SQL'
ALTER TABLE gar_import_operations
    DROP CONSTRAINT chk_gar_import_operations_phase,
    ADD COLUMN defer_foreign_keys boolean NOT NULL DEFAULT false,
    ADD COLUMN foreign_keys_total integer NOT NULL DEFAULT 0,
    ADD COLUMN foreign_keys_completed integer NOT NULL DEFAULT 0,
    ADD COLUMN foreign_key_restore_started_at timestamptz,
    ADD COLUMN foreign_key_restore_completed_at timestamptz,
    ADD COLUMN foreign_key_validation_started_at timestamptz,
    ADD COLUMN foreign_key_validation_completed_at timestamptz,
    ADD CONSTRAINT chk_gar_import_operations_phase
        CHECK (
            phase IN (
                'prepare', 'import', 'index-build', 'foreign-key-restore',
                'foreign-key-validate', 'analyze', 'integrity', 'ready'
            )
        ),
    ADD CONSTRAINT chk_gar_import_operations_foreign_key_mode
        CHECK (
            NOT defer_foreign_keys
            OR (mode = 'initial-load' AND write_mode = 'direct-copy')
        ),
    ADD CONSTRAINT chk_gar_import_operations_foreign_key_counts
        CHECK (
            foreign_keys_total >= 0
            AND foreign_keys_completed >= 0
            AND foreign_keys_completed <= foreign_keys_total
        )
SQL);
        $b->execute(<<<'SQL'
CREATE TABLE gar_import_foreign_key_snapshots (
    operation_id bigint NOT NULL REFERENCES gar_import_operations(id) ON DELETE CASCADE,
    table_name text NOT NULL,
    constraint_name text NOT NULL,
    definition_sql text NOT NULL,
    definition_hash char(64) NOT NULL,
    status varchar(16) NOT NULL DEFAULT 'pending',
    attempt integer NOT NULL DEFAULT 0,
    started_at timestamptz,
    completed_at timestamptz,
    error_message text,
    PRIMARY KEY (operation_id, table_name, constraint_name),
    CONSTRAINT chk_gar_import_foreign_key_snapshots_status
        CHECK (status IN ('pending', 'restored', 'validating', 'completed', 'failed')),
    CONSTRAINT chk_gar_import_foreign_key_snapshots_attempt CHECK (attempt >= 0)
)
SQL);
        $b->execute(<<<'SQL'
CREATE INDEX idx_gar_import_foreign_key_snapshots_operation_status
ON gar_import_foreign_key_snapshots (operation_id, status, table_name, constraint_name)
SQL);
        $b->execute(
            "COMMENT ON COLUMN gar_import_operations.defer_foreign_keys IS 'Initial-load snapshot/drop/restore/validate lifecycle только для canonical foreign keys'",
        );
        $b->execute(
            "COMMENT ON TABLE gar_import_foreign_key_snapshots IS 'Durable definitions и validation checkpoints временно снятых canonical GAR foreign keys'",
        );
    }

    public function down(MigrationBuilder $b): void
    {
        $b->execute('DROP TABLE IF EXISTS gar_import_foreign_key_snapshots');
        $b->execute(<<<'SQL'
ALTER TABLE gar_import_operations
    DROP CONSTRAINT IF EXISTS chk_gar_import_operations_foreign_key_counts,
    DROP CONSTRAINT IF EXISTS chk_gar_import_operations_foreign_key_mode,
    DROP CONSTRAINT IF EXISTS chk_gar_import_operations_phase,
    DROP COLUMN IF EXISTS foreign_key_validation_completed_at,
    DROP COLUMN IF EXISTS foreign_key_validation_started_at,
    DROP COLUMN IF EXISTS foreign_key_restore_completed_at,
    DROP COLUMN IF EXISTS foreign_key_restore_started_at,
    DROP COLUMN IF EXISTS foreign_keys_completed,
    DROP COLUMN IF EXISTS foreign_keys_total,
    DROP COLUMN IF EXISTS defer_foreign_keys,
    ADD CONSTRAINT chk_gar_import_operations_phase
        CHECK (phase IN ('prepare', 'import', 'index-build', 'analyze', 'integrity', 'ready'))
SQL);
    }
}
