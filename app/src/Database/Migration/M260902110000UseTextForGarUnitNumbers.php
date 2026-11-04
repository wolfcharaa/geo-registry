<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

final class M260902110000UseTextForGarUnitNumbers implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    /**
     * @var list<string>
     */
    private const TABLES = ['apartments', 'car_places', 'rooms', 'steads'];

    public function up(MigrationBuilder $b): void
    {
        foreach (self::TABLES as $table) {
            $b->execute("ALTER TABLE {$table} ALTER COLUMN number TYPE text");
            $b->execute(
                "COMMENT ON COLUMN {$table}.number IS 'Номер объекта из GAR NUMBER; хранится как text, потому что реальные значения могут быть длиннее 50 символов.'",
            );
        }
    }

    public function down(MigrationBuilder $b): void
    {
        foreach (self::TABLES as $table) {
            $b->execute("ALTER TABLE {$table} ALTER COLUMN number TYPE varchar(50)");
            $b->execute("COMMENT ON COLUMN {$table}.number IS 'Номер объекта из GAR NUMBER.'");
        }
    }
}
