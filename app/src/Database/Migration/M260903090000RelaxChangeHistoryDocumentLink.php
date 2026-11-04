<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

final class M260903090000RelaxChangeHistoryDocumentLink implements RevertibleMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute('ALTER TABLE change_history DROP CONSTRAINT IF EXISTS change_history_ndoc_id_fkey');
        $b->execute(
            "COMMENT ON COLUMN change_history.ndoc_id IS 'Нормативный документ из AS_CHANGE_HISTORY.NDOCID; soft reference, в реальных GAR-выгрузках документ может отсутствовать в AS_NORMATIVE_DOCS.'",
        );
    }

    public function down(MigrationBuilder $b): void
    {
        $b->execute(
            'ALTER TABLE change_history ADD CONSTRAINT change_history_ndoc_id_fkey FOREIGN KEY (ndoc_id) REFERENCES normative_docs(id) ON DELETE SET NULL',
        );
        $b->execute(
            "COMMENT ON COLUMN change_history.ndoc_id IS 'Нормативный документ из AS_CHANGE_HISTORY.NDOCID; в GAR может отсутствовать.'",
        );
    }
}
