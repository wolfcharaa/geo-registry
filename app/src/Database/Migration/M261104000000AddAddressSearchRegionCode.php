<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

final class M261104000000AddAddressSearchRegionCode implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute(<<<'SQL'
ALTER TABLE address_search
ADD COLUMN region_code smallint GENERATED ALWAYS AS (
    CASE
        WHEN okato ~ '^[0-9]{2}' THEN substring(okato FROM 1 FOR 2)::smallint
        ELSE NULL
    END
) STORED
SQL);
        $b->execute(<<<'SQL'
COMMENT ON COLUMN address_search.region_code IS
'Региональный ключ online search; вычисляется из ОКАТО до перехода на partitioned projection'
SQL);
        $b->execute(<<<'SQL'
CREATE INDEX idx_address_search_region_hierarchy
ON address_search USING BTREE (region_code, hierarchy_type)
SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        $b->execute('DROP INDEX IF EXISTS idx_address_search_region_hierarchy');
        $b->execute('ALTER TABLE address_search DROP COLUMN IF EXISTS region_code');
    }
}
