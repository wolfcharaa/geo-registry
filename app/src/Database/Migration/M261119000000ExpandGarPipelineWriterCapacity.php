<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

final class M261119000000ExpandGarPipelineWriterCapacity implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $this->replaceConstraint($b, 8);
        $b->execute(
            "COMMENT ON COLUMN gar_import_operations.pipeline_writers IS 'Последний запрошенный предел process writers: общий для gar:import или на primary для gar:import:raw; не входит в identity recovery'",
        );
    }

    public function down(MigrationBuilder $b): void
    {
        $this->replaceConstraint($b, 4);
        $b->execute(
            "COMMENT ON COLUMN gar_import_operations.pipeline_writers IS 'Последний запрошенный предел batch writers; не входит в identity recovery'",
        );
    }

    private function replaceConstraint(MigrationBuilder $b, int $maximumWriters): void
    {
        $b->execute(<<<SQL
ALTER TABLE gar_import_operations
    DROP CONSTRAINT chk_gar_import_operations_pipeline_identity,
    ADD CONSTRAINT chk_gar_import_operations_pipeline_identity
        CHECK (
            (pipeline_file_key IS NULL AND pipeline_writers IS NULL)
            OR (
                pipeline_file_key IS NOT NULL
                AND pipeline_writers BETWEEN 1 AND {$maximumWriters}
                AND mode = 'initial-load'
                AND write_mode = 'direct-copy'
            )
        )
SQL);
    }
}
