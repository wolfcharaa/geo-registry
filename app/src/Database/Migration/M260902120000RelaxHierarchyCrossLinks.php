<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

final class M260902120000RelaxHierarchyCrossLinks implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    /**
     * @var array<string, string>
     */
    private const TABLES = [
        'administrative_reestr_hierarchy' => 'административной иерархии',
        'municipal_reestr_hierarchy' => 'муниципальной иерархии',
    ];

    public function up(MigrationBuilder $b): void
    {
        foreach (self::TABLES as $table => $label) {
            $b->execute("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_object_id_fkey");
            $b->execute("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_parent_obj_id_fkey");
            $b->execute("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_change_id_fkey");
            $b->execute(
                "COMMENT ON COLUMN {$table}.object_id IS 'Идентификатор объекта {$label} из OBJECTID; хранится как raw reference, потому что GAR может содержать отсутствующие в AS_REESTR_OBJECTS значения.'",
            );
            $b->execute(
                "COMMENT ON COLUMN {$table}.parent_obj_id IS 'Родительский объект {$label} из PARENTOBJID; хранится как raw reference и может отсутствовать в AS_REESTR_OBJECTS.'",
            );
            $b->execute(
                "COMMENT ON COLUMN {$table}.change_id IS 'Идентификатор изменения {$label} из CHANGEID; хранится как raw reference и может отсутствовать в AS_CHANGE_HISTORY.'",
            );
        }
    }

    public function down(MigrationBuilder $b): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            $b->execute(
                "ALTER TABLE {$table} ADD CONSTRAINT {$table}_object_id_fkey FOREIGN KEY (object_id) REFERENCES reestr_objects(id) ON DELETE CASCADE",
            );
            $b->execute(
                "ALTER TABLE {$table} ADD CONSTRAINT {$table}_parent_obj_id_fkey FOREIGN KEY (parent_obj_id) REFERENCES reestr_objects(id) ON DELETE SET NULL",
            );
            $b->execute(
                "ALTER TABLE {$table} ADD CONSTRAINT {$table}_change_id_fkey FOREIGN KEY (change_id) REFERENCES change_history(id) ON DELETE SET NULL",
            );
        }
    }
}
