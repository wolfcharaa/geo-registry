<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

final class M260902090000AllowPartialHouseNumbers implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute('ALTER TABLE houses ALTER COLUMN house_num DROP NOT NULL');
        $b->execute('ALTER TABLE houses ALTER COLUMN house_type_id DROP NOT NULL');
        $b->execute(
            "COMMENT ON COLUMN houses.house_num IS 'Основной номер дома из AS_HOUSES.HOUSENUM; в GAR может отсутствовать, если адрес задан через ADDNUM1/ADDNUM2.'",
        );
        $b->execute(
            "COMMENT ON COLUMN houses.house_type_id IS 'Основной тип дома из AS_HOUSES.HOUSETYPE; в GAR может отсутствовать, если адрес задан через ADDTYPE1/ADDTYPE2.'",
        );
    }

    public function down(MigrationBuilder $b): void
    {
        $b->execute('ALTER TABLE houses ALTER COLUMN house_num SET NOT NULL');
        $b->execute('ALTER TABLE houses ALTER COLUMN house_type_id SET NOT NULL');
        $b->execute("COMMENT ON COLUMN houses.house_num IS 'Основной номер дома из AS_HOUSES.HOUSENUM.'");
        $b->execute("COMMENT ON COLUMN houses.house_type_id IS 'Основной тип дома из AS_HOUSES.HOUSETYPE.'");
    }
}
