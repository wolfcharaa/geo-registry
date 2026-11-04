<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

final class M260902150000CreateAddressSearchBuildProgress implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        foreach ($this->upSql() as $sql) {
            $b->execute($sql);
        }

        foreach ($this->columnComments() as $column => $comment) {
            $b->execute("COMMENT ON COLUMN address_search_build_progress.{$column} IS " . $b->getDb()->getQuoter()->quoteValue($comment));
        }
    }

    public function down(MigrationBuilder $b): void
    {
        $b->execute('DROP TABLE IF EXISTS address_search_build_progress');
    }

    /**
     * @return list<string>
     */
    private function upSql(): array
    {
        return [
            <<<'SQL'
CREATE TABLE address_search_build_progress (
    hierarchy_type varchar(3) PRIMARY KEY,
    last_hierarchy_id bigint NOT NULL DEFAULT 0,
    scanned_rows bigint NOT NULL DEFAULT 0,
    written_rows bigint NOT NULL DEFAULT 0,
    started_at timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at timestamp(0) without time zone,
    CONSTRAINT chk_address_search_build_progress_hierarchy_type CHECK (hierarchy_type IN ('adm', 'mun'))
)
SQL,
            "COMMENT ON TABLE address_search_build_progress IS 'Checkpoint построения address_search по GAR hierarchy id'",
            'CREATE INDEX idx_address_search_build_progress_updated_at ON address_search_build_progress USING BTREE (updated_at)',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function columnComments(): array
    {
        return [
            'hierarchy_type' => 'Тип иерархии address_search: adm или mun.',
            'last_hierarchy_id' => 'Последний просканированный id из соответствующей GAR hierarchy таблицы.',
            'scanned_rows' => 'Суммарное количество просканированных hierarchy rows в текущем/последнем build-процессе.',
            'written_rows' => 'Суммарное количество записанных или обновленных rows в address_search.',
            'started_at' => 'Дата и время старта текущего/последнего build-процесса.',
            'updated_at' => 'Дата и время последнего обновления checkpoint.',
            'completed_at' => 'Дата и время полного прохода до конца hierarchy таблицы; NULL означает незавершенный build.',
        ];
    }
}
