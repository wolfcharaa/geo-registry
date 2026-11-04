<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

final class M260903050000IndexParserLogs implements RevertibleMigrationInterface, TransactionalMigrationInterface
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
            'CREATE INDEX idx_parser_logs_logged_at ON parser_logs USING BTREE (logged_at DESC)',
            'CREATE INDEX idx_parser_logs_level ON parser_logs USING BTREE (level)',
            "CREATE INDEX idx_parser_logs_operation ON parser_logs USING BTREE ((details->>'operation'))",
            "CREATE INDEX idx_parser_logs_run_id ON parser_logs USING BTREE ((details->>'run_id'))",
            'CREATE INDEX idx_parser_logs_details ON parser_logs USING GIN (details)',
        ];
    }

    /**
     * @return list<string>
     */
    private function downSql(): array
    {
        return [
            'DROP INDEX IF EXISTS idx_parser_logs_details',
            'DROP INDEX IF EXISTS idx_parser_logs_run_id',
            'DROP INDEX IF EXISTS idx_parser_logs_operation',
            'DROP INDEX IF EXISTS idx_parser_logs_level',
            'DROP INDEX IF EXISTS idx_parser_logs_logged_at',
        ];
    }
}
