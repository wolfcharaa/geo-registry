<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

final class M260903120000AddAddressSearchTaxCodes implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        foreach ($this->upSql() as $sql) {
            $b->execute($sql);
        }

        foreach ($this->columnComments() as $column => $comment) {
            $b->execute("COMMENT ON COLUMN address_search.{$column} IS " . $b->getDb()->getQuoter()->quoteValue($comment));
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
            'ALTER TABLE address_search ADD COLUMN IF NOT EXISTS okato varchar(20)',
            'ALTER TABLE address_search ADD COLUMN IF NOT EXISTS oktmo varchar(20)',
            'CREATE INDEX IF NOT EXISTS idx_address_search_okato ON address_search USING BTREE (okato)',
            'CREATE INDEX IF NOT EXISTS idx_address_search_oktmo ON address_search USING BTREE (oktmo)',
        ];
    }

    /**
     * @return list<string>
     */
    private function downSql(): array
    {
        return [
            'DROP INDEX IF EXISTS idx_address_search_oktmo',
            'DROP INDEX IF EXISTS idx_address_search_okato',
            'ALTER TABLE address_search DROP COLUMN IF EXISTS oktmo',
            'ALTER TABLE address_search DROP COLUMN IF EXISTS okato',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function columnComments(): array
    {
        return [
            'okato' => 'ОКАТО из параметров GAR с TYPEID=6, выбранный по конечному объекту или ближайшему объекту в его PATH.',
            'oktmo' => 'ОКТМО из параметров GAR с TYPEID=7 или из AS_MUN_HIERARCHY.OKTMO для муниципальной иерархии.',
        ];
    }
}
