<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

final class M260902140000AddAddressSearchBuildIndexes implements RevertibleMigrationInterface
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
            <<<'SQL'
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_object_params_denorm_type_object_update
    ON object_params (type_id, object_id, update_date DESC, id DESC)
    WHERE type_id IN (5, 6, 7, 16)
SQL,
            <<<'SQL'
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_address_objects_denorm_active_reestr
    ON address_objects (reestr_object_id, update_date DESC, id DESC)
    WHERE is_active = true AND is_actual = true
SQL,
            <<<'SQL'
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_houses_denorm_active_reestr
    ON houses (reestr_object_id, update_date DESC, id DESC)
    WHERE is_active = true AND is_actual = true
SQL,
            <<<'SQL'
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_apartments_denorm_active_reestr
    ON apartments (reestr_object_id, update_date DESC, id DESC)
    WHERE is_active = true AND is_actual = true
SQL,
            <<<'SQL'
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_rooms_denorm_active_reestr
    ON rooms (reestr_object_id, update_date DESC, id DESC)
    WHERE is_active = true AND is_actual = true
SQL,
            <<<'SQL'
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_steads_denorm_active_object
    ON steads (object_id, update_date DESC, id DESC)
    WHERE is_active = true AND is_actual = true
SQL,
            <<<'SQL'
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_car_places_denorm_active_reestr
    ON car_places (reestr_object_id, update_date DESC, id DESC)
    WHERE is_active = true AND is_actual = true
SQL,
        ];
    }

    /**
     * @return list<string>
     */
    private function downSql(): array
    {
        return [
            'DROP INDEX CONCURRENTLY IF EXISTS idx_car_places_denorm_active_reestr',
            'DROP INDEX CONCURRENTLY IF EXISTS idx_steads_denorm_active_object',
            'DROP INDEX CONCURRENTLY IF EXISTS idx_rooms_denorm_active_reestr',
            'DROP INDEX CONCURRENTLY IF EXISTS idx_apartments_denorm_active_reestr',
            'DROP INDEX CONCURRENTLY IF EXISTS idx_houses_denorm_active_reestr',
            'DROP INDEX CONCURRENTLY IF EXISTS idx_address_objects_denorm_active_reestr',
            'DROP INDEX CONCURRENTLY IF EXISTS idx_object_params_denorm_type_object_update',
        ];
    }
}
