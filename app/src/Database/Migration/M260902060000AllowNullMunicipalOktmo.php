<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

final class M260902060000AllowNullMunicipalOktmo implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute('ALTER TABLE municipal_reestr_hierarchy ALTER COLUMN oktmo DROP NOT NULL');
        $b->execute(
            "COMMENT ON COLUMN municipal_reestr_hierarchy.oktmo IS 'ОКТМО из AS_MUN_HIERARCHY.OKTMO; в реальных GAR-выгрузках поле может отсутствовать.'",
        );
    }

    public function down(MigrationBuilder $b): void
    {
        $b->execute('ALTER TABLE municipal_reestr_hierarchy ALTER COLUMN oktmo SET NOT NULL');
        $b->execute("COMMENT ON COLUMN municipal_reestr_hierarchy.oktmo IS 'ОКТМО из AS_MUN_HIERARCHY.OKTMO.'");
    }
}
