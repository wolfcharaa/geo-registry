<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

final class M260903100000CreateGarImportIndexSnapshots implements RevertibleMigrationInterface, TransactionalMigrationInterface
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
CREATE TABLE IF NOT EXISTS gar_import_index_snapshots (
    index_name text PRIMARY KEY,
    table_name text NOT NULL,
    create_sql text NOT NULL,
    dropped_at timestamptz NOT NULL DEFAULT now()
)
SQL,
            "COMMENT ON TABLE gar_import_index_snapshots IS 'Снимки SQL-определений вторичных индексов, временно снятых на время большого GAR-импорта'",
            "COMMENT ON COLUMN gar_import_index_snapshots.index_name IS 'Имя индекса в public schema'",
            "COMMENT ON COLUMN gar_import_index_snapshots.table_name IS 'GAR-таблица, к которой относится индекс'",
            "COMMENT ON COLUMN gar_import_index_snapshots.create_sql IS 'SQL из pg_get_indexdef для восстановления индекса'",
            "COMMENT ON COLUMN gar_import_index_snapshots.dropped_at IS 'Время последнего снятия индекса'",
            'CREATE INDEX IF NOT EXISTS idx_gar_import_index_snapshots_table_name ON gar_import_index_snapshots (table_name)',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS address_search_index_snapshots (
    index_name text PRIMARY KEY,
    create_sql text NOT NULL,
    dropped_at timestamptz NOT NULL DEFAULT now()
)
SQL,
            "COMMENT ON TABLE address_search_index_snapshots IS 'Снимки SQL-определений вторичных индексов address_search, временно снятых на время bulk rebuild'",
            "COMMENT ON COLUMN address_search_index_snapshots.index_name IS 'Имя индекса в public schema'",
            "COMMENT ON COLUMN address_search_index_snapshots.create_sql IS 'SQL из pg_get_indexdef для восстановления индекса'",
            "COMMENT ON COLUMN address_search_index_snapshots.dropped_at IS 'Время последнего снятия индекса'",
        ];
    }

    /**
     * @return list<string>
     */
    private function downSql(): array
    {
        return [
            'DROP TABLE IF EXISTS address_search_index_snapshots',
            'DROP TABLE IF EXISTS gar_import_index_snapshots',
        ];
    }
}
