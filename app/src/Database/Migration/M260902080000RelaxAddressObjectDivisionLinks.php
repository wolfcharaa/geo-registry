<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

final class M260902080000RelaxAddressObjectDivisionLinks implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute(
            'ALTER TABLE address_object_divisions DROP CONSTRAINT IF EXISTS address_object_divisions_parent_id_fkey',
        );
        $b->execute(
            'ALTER TABLE address_object_divisions DROP CONSTRAINT IF EXISTS address_object_divisions_child_id_fkey',
        );
        $b->execute(
            "COMMENT ON COLUMN address_object_divisions.parent_id IS 'Родительский идентификатор из AS_ADDR_OBJ_DIVISION.PARENTID; в GAR может ссылаться на объект, отсутствующий в текущем региональном AS_ADDR_OBJ.'",
        );
        $b->execute(
            "COMMENT ON COLUMN address_object_divisions.child_id IS 'Дочерний идентификатор из AS_ADDR_OBJ_DIVISION.CHILDID; в GAR может ссылаться на объект, отсутствующий в текущем региональном AS_ADDR_OBJ.'",
        );
    }

    public function down(MigrationBuilder $b): void
    {
        $b->execute(
            'ALTER TABLE address_object_divisions ADD CONSTRAINT address_object_divisions_parent_id_fkey FOREIGN KEY (parent_id) REFERENCES address_objects(id) ON DELETE CASCADE',
        );
        $b->execute(
            'ALTER TABLE address_object_divisions ADD CONSTRAINT address_object_divisions_child_id_fkey FOREIGN KEY (child_id) REFERENCES address_objects(id) ON DELETE CASCADE',
        );
        $b->execute("COMMENT ON COLUMN address_object_divisions.parent_id IS 'Родительский адресный объект из AS_ADDR_OBJ_DIVISION.PARENTID.'");
        $b->execute("COMMENT ON COLUMN address_object_divisions.child_id IS 'Дочерний адресный объект из AS_ADDR_OBJ_DIVISION.CHILDID.'");
    }
}
