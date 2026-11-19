<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

final class M261111000000AddGarDirectCopyMode implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute(<<<'SQL'
ALTER TABLE gar_import_operations
    ADD COLUMN write_mode varchar(16) NOT NULL DEFAULT 'merge',
    ADD CONSTRAINT chk_gar_import_operations_write_mode
        CHECK (
            write_mode IN ('merge', 'direct-copy')
            AND (write_mode = 'merge' OR mode = 'initial-load')
        )
SQL);
        $b->execute(
            "COMMENT ON COLUMN gar_import_operations.write_mode IS 'merge использует staging/upsert; direct-copy пишет initial-load batches сразу в canonical tables'",
        );
    }

    public function down(MigrationBuilder $b): void
    {
        $b->execute(<<<'SQL'
ALTER TABLE gar_import_operations
    DROP CONSTRAINT IF EXISTS chk_gar_import_operations_write_mode,
    DROP COLUMN IF EXISTS write_mode
SQL);
    }
}
