<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

final class M260902070000AllowNullNormativeDocName implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute('ALTER TABLE normative_docs ALTER COLUMN name DROP NOT NULL');
        $b->execute(
            "COMMENT ON COLUMN normative_docs.name IS 'Наименование нормативного документа из AS_NORMATIVE_DOCS.NAME; в реальных GAR-выгрузках поле может отсутствовать.'",
        );
    }

    public function down(MigrationBuilder $b): void
    {
        $b->execute('ALTER TABLE normative_docs ALTER COLUMN name SET NOT NULL');
        $b->execute("COMMENT ON COLUMN normative_docs.name IS 'Наименование нормативного документа из AS_NORMATIVE_DOCS.NAME.'");
    }
}
