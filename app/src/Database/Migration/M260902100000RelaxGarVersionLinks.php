<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

final class M260902100000RelaxGarVersionLinks implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    /**
     * @var array<string, string>
     */
    private const TABLES = [
        'address_objects' => 'адресного объекта',
        'apartments' => 'помещения',
        'car_places' => 'машино-места',
        'houses' => 'дома',
        'rooms' => 'комнаты',
        'steads' => 'участка',
        'administrative_reestr_hierarchy' => 'записи административной иерархии',
        'municipal_reestr_hierarchy' => 'записи муниципальной иерархии',
    ];

    public function up(MigrationBuilder $b): void
    {
        foreach (self::TABLES as $table => $label) {
            $b->execute("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_prev_id_fkey");
            $b->execute("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_next_id_fkey");
            $b->execute(
                "COMMENT ON COLUMN {$table}.prev_id IS 'Предыдущая версия {$label} из PREVID; в GAR может ссылаться на запись, отсутствующую в текущей выгрузке.'",
            );
            $b->execute(
                "COMMENT ON COLUMN {$table}.next_id IS 'Следующая версия {$label} из NEXTID; в GAR может ссылаться на запись, отсутствующую в текущей выгрузке.'",
            );
        }
    }

    public function down(MigrationBuilder $b): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            $b->execute(
                "ALTER TABLE {$table} ADD CONSTRAINT {$table}_prev_id_fkey FOREIGN KEY (prev_id) REFERENCES {$table}(id) ON DELETE SET NULL",
            );
            $b->execute(
                "ALTER TABLE {$table} ADD CONSTRAINT {$table}_next_id_fkey FOREIGN KEY (next_id) REFERENCES {$table}(id) ON DELETE SET NULL",
            );
        }
    }
}
