<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

final class M261111030000AddGarMultiFilePipeline implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute(<<<'SQL'
ALTER TABLE gar_import_files
    ADD COLUMN pipeline_enabled boolean NOT NULL DEFAULT false
SQL);
        $b->execute(<<<'SQL'
UPDATE gar_import_files file
SET pipeline_enabled = true
FROM gar_import_operations operation
WHERE operation.id = file.operation_id
  AND operation.pipeline_file_key = file.file_key
SQL);
        $b->execute(
            "COMMENT ON COLUMN gar_import_files.pipeline_enabled IS 'XML включён в детерминированный bounded batch pipeline операции'",
        );
        $b->execute(
            "COMMENT ON COLUMN gar_import_operations.pipeline_file_key IS 'Manifest file_key одного XML либо SHA-256 упорядоченного multi-file pipeline selection'",
        );
    }

    public function down(MigrationBuilder $b): void
    {
        $b->execute(<<<'SQL'
UPDATE gar_import_operations operation
SET pipeline_file_key = selected.file_key
FROM (
    SELECT operation_id, min(file_key) AS file_key
    FROM gar_import_files
    WHERE pipeline_enabled
    GROUP BY operation_id
) selected
WHERE selected.operation_id = operation.id
SQL);
        $b->execute(<<<'SQL'
ALTER TABLE gar_import_files
    DROP COLUMN IF EXISTS pipeline_enabled
SQL);
        $b->execute(
            "COMMENT ON COLUMN gar_import_operations.pipeline_file_key IS 'Manifest file_key единственного XML, выбранного для bounded batch pipeline'",
        );
    }
}
