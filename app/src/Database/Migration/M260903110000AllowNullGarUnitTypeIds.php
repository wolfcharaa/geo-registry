<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

final class M260903110000AllowNullGarUnitTypeIds implements RevertibleMigrationInterface, TransactionalMigrationInterface
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

    /**
     * @return list<string>
     */
    private function upSql(): array
    {
        return [
            'ALTER TABLE apartments ALTER COLUMN apart_type_id DROP NOT NULL',
            "COMMENT ON COLUMN apartments.apart_type_id IS 'Тип помещения из AS_APARTMENTS.APARTTYPE; в реальных GAR-выгрузках может быть 0 или отсутствовать.'",
            'ALTER TABLE rooms ALTER COLUMN room_type_id DROP NOT NULL',
            "COMMENT ON COLUMN rooms.room_type_id IS 'Тип комнаты из AS_ROOMS.ROOMTYPE; в реальных GAR-выгрузках может быть 0 или отсутствовать.'",
        ];
    }

    /**
     * @return list<string>
     */
    private function downSql(): array
    {
        return [
            "COMMENT ON COLUMN rooms.room_type_id IS 'Тип комнаты из AS_ROOMS.ROOMTYPE.'",
            'ALTER TABLE rooms ALTER COLUMN room_type_id SET NOT NULL',
            "COMMENT ON COLUMN apartments.apart_type_id IS 'Тип помещения из AS_APARTMENTS.APARTTYPE.'",
            'ALTER TABLE apartments ALTER COLUMN apart_type_id SET NOT NULL',
        ];
    }
}
