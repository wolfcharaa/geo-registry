<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

final class M261110000000AddGarInitialLoadLifecycle implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        foreach ($this->upSql() as $sql) {
            $b->execute($sql);
        }
    }

    public function down(MigrationBuilder $b): void
    {
        foreach ($this->downSql() as $sql) {
            $b->execute($sql);
        }
    }

    /** @return list<string> */
    private function upSql(): array
    {
        return [
            <<<'SQL'
ALTER TABLE gar_import_operations
    ADD COLUMN mode varchar(16) NOT NULL DEFAULT 'standard',
    ADD COLUMN phase varchar(24) NOT NULL DEFAULT 'import',
    ADD COLUMN indexes_total integer NOT NULL DEFAULT 0,
    ADD COLUMN indexes_completed integer NOT NULL DEFAULT 0,
    ADD COLUMN index_build_started_at timestamptz,
    ADD COLUMN index_build_completed_at timestamptz,
    ADD COLUMN analyzed_at timestamptz,
    ADD COLUMN integrity_checked_at timestamptz
SQL,
            <<<'SQL'
UPDATE gar_import_operations
SET phase = 'ready'
WHERE status = 'completed'
SQL,
            <<<'SQL'
ALTER TABLE gar_import_operations
    ADD CONSTRAINT chk_gar_import_operations_mode
        CHECK (mode IN ('standard', 'initial-load')),
    ADD CONSTRAINT chk_gar_import_operations_phase
        CHECK (phase IN ('prepare', 'import', 'index-build', 'analyze', 'integrity', 'ready')),
    ADD CONSTRAINT chk_gar_import_operations_index_counts
        CHECK (
            indexes_total >= 0
            AND indexes_completed >= 0
            AND indexes_completed <= indexes_total
        )
SQL,
            "COMMENT ON COLUMN gar_import_operations.mode IS 'standard сохраняет индексы; initial-load временно снимает вторичные индексы пустой выделенной БД'",
            "COMMENT ON COLUMN gar_import_operations.phase IS 'Возобновляемая фаза initial-load: prepare, import, index-build, analyze, integrity, ready'",
            <<<'SQL'
ALTER TABLE gar_import_index_snapshots
    ADD COLUMN operation_id bigint REFERENCES gar_import_operations(id) ON DELETE CASCADE,
    ADD COLUMN definition_hash char(64),
    ADD COLUMN status varchar(16) NOT NULL DEFAULT 'pending',
    ADD COLUMN attempt integer NOT NULL DEFAULT 0,
    ADD COLUMN started_at timestamptz,
    ADD COLUMN completed_at timestamptz,
    ADD COLUMN error_message text
SQL,
            <<<'SQL'
ALTER TABLE gar_import_index_snapshots
    ADD CONSTRAINT chk_gar_import_index_snapshots_status
        CHECK (status IN ('pending', 'building', 'completed', 'failed')),
    ADD CONSTRAINT chk_gar_import_index_snapshots_attempt
        CHECK (attempt >= 0)
SQL,
            <<<'SQL'
CREATE INDEX idx_gar_import_index_snapshots_operation_status
ON gar_import_index_snapshots (operation_id, status, table_name, index_name)
SQL,
            "COMMENT ON COLUMN gar_import_index_snapshots.operation_id IS 'Initial-load operation, которая владеет снимком; NULL допустим только для legacy-снимков'",
            "COMMENT ON COLUMN gar_import_index_snapshots.definition_hash IS 'SHA-256 SQL-определения для fail-closed проверки восстановленного индекса'",
            "COMMENT ON COLUMN gar_import_index_snapshots.status IS 'Возобновляемое состояние восстановления индекса'",
        ];
    }

    /** @return list<string> */
    private function downSql(): array
    {
        return [
            'DROP INDEX IF EXISTS idx_gar_import_index_snapshots_operation_status',
            <<<'SQL'
ALTER TABLE gar_import_index_snapshots
    DROP CONSTRAINT IF EXISTS chk_gar_import_index_snapshots_attempt,
    DROP CONSTRAINT IF EXISTS chk_gar_import_index_snapshots_status,
    DROP COLUMN IF EXISTS error_message,
    DROP COLUMN IF EXISTS completed_at,
    DROP COLUMN IF EXISTS started_at,
    DROP COLUMN IF EXISTS attempt,
    DROP COLUMN IF EXISTS status,
    DROP COLUMN IF EXISTS definition_hash,
    DROP COLUMN IF EXISTS operation_id
SQL,
            <<<'SQL'
ALTER TABLE gar_import_operations
    DROP CONSTRAINT IF EXISTS chk_gar_import_operations_index_counts,
    DROP CONSTRAINT IF EXISTS chk_gar_import_operations_phase,
    DROP CONSTRAINT IF EXISTS chk_gar_import_operations_mode,
    DROP COLUMN IF EXISTS integrity_checked_at,
    DROP COLUMN IF EXISTS analyzed_at,
    DROP COLUMN IF EXISTS index_build_completed_at,
    DROP COLUMN IF EXISTS index_build_started_at,
    DROP COLUMN IF EXISTS indexes_completed,
    DROP COLUMN IF EXISTS indexes_total,
    DROP COLUMN IF EXISTS phase,
    DROP COLUMN IF EXISTS mode
SQL,
        ];
    }
}
