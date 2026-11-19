<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

final class M261111010000AddGarImportPipelineState implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute(<<<'SQL'
ALTER TABLE gar_import_operations
    ADD COLUMN pipeline_file_key char(64),
    ADD COLUMN pipeline_writers smallint,
    ADD COLUMN pipeline_batches bigint NOT NULL DEFAULT 0,
    ADD COLUMN pipeline_bytes bigint NOT NULL DEFAULT 0,
    ADD COLUMN pipeline_peak_in_flight_batches smallint NOT NULL DEFAULT 0,
    ADD COLUMN pipeline_peak_in_flight_bytes bigint NOT NULL DEFAULT 0,
    ADD CONSTRAINT chk_gar_import_operations_pipeline_identity
        CHECK (
            (pipeline_file_key IS NULL AND pipeline_writers IS NULL)
            OR (
                pipeline_file_key IS NOT NULL
                AND pipeline_writers BETWEEN 1 AND 4
                AND mode = 'initial-load'
                AND write_mode = 'direct-copy'
            )
        ),
    ADD CONSTRAINT chk_gar_import_operations_pipeline_counters
        CHECK (
            pipeline_batches >= 0
            AND pipeline_bytes >= 0
            AND pipeline_peak_in_flight_batches >= 0
            AND pipeline_peak_in_flight_bytes >= 0
        )
SQL);
        $b->execute(<<<'SQL'
CREATE TABLE gar_import_file_batches (
    operation_id bigint NOT NULL,
    file_key char(64) NOT NULL,
    first_row bigint NOT NULL,
    last_row bigint NOT NULL,
    encoded_bytes bigint NOT NULL,
    committed_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (operation_id, file_key, first_row),
    CONSTRAINT fk_gar_import_file_batches_file
        FOREIGN KEY (operation_id, file_key)
        REFERENCES gar_import_files (operation_id, file_key)
        ON DELETE CASCADE,
    CONSTRAINT chk_gar_import_file_batches_rows
        CHECK (first_row > 0 AND last_row >= first_row),
    CONSTRAINT chk_gar_import_file_batches_bytes CHECK (encoded_bytes > 0)
)
SQL);
        $b->execute(
            "COMMENT ON TABLE gar_import_file_batches IS 'Непрерывные диапазоны direct-COPY batches, зафиксированные впереди contiguous file checkpoint'",
        );
        $b->execute(
            "COMMENT ON COLUMN gar_import_operations.pipeline_file_key IS 'Manifest file_key единственного XML, выбранного для bounded batch pipeline'",
        );
        $b->execute(
            "COMMENT ON COLUMN gar_import_operations.pipeline_writers IS 'Последний запрошенный предел batch writers; не входит в identity recovery'",
        );
    }

    public function down(MigrationBuilder $b): void
    {
        $b->execute('DROP TABLE IF EXISTS gar_import_file_batches');
        $b->execute(<<<'SQL'
ALTER TABLE gar_import_operations
    DROP CONSTRAINT IF EXISTS chk_gar_import_operations_pipeline_counters,
    DROP CONSTRAINT IF EXISTS chk_gar_import_operations_pipeline_identity,
    DROP COLUMN IF EXISTS pipeline_peak_in_flight_bytes,
    DROP COLUMN IF EXISTS pipeline_peak_in_flight_batches,
    DROP COLUMN IF EXISTS pipeline_bytes,
    DROP COLUMN IF EXISTS pipeline_batches,
    DROP COLUMN IF EXISTS pipeline_writers,
    DROP COLUMN IF EXISTS pipeline_file_key
SQL);
    }
}
