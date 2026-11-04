<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

final class M261104040000UseGarRegionCodeForAddressSearch implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute(<<<'SQL'
DO $migration$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'address_search'
          AND column_name = 'region_code'
          AND is_generated = 'ALWAYS'
    ) THEN
        ALTER TABLE address_search
            ALTER COLUMN region_code DROP EXPRESSION;
    END IF;
END
$migration$
SQL);
        $b->execute(<<<'SQL'
WITH active_regions AS (
    SELECT DISTINCT ON (object_id)
        object_id,
        region_code::smallint AS region_code
    FROM administrative_reestr_hierarchy
    WHERE is_active = true
      AND end_date > CURRENT_DATE
      AND region_code BETWEEN 1 AND 99
    ORDER BY object_id, update_date DESC, id DESC
)
UPDATE address_search search
SET region_code = active_regions.region_code
FROM active_regions
WHERE search.object_id = active_regions.object_id
  AND search.region_code IS DISTINCT FROM active_regions.region_code
SQL);
        $b->execute(<<<'SQL'
DO $migration$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conrelid = 'address_search'::regclass
          AND conname = 'chk_address_search_region_code'
    ) THEN
        ALTER TABLE address_search
            ADD CONSTRAINT chk_address_search_region_code
            CHECK (region_code BETWEEN 1 AND 99);
    END IF;
END
$migration$
SQL);
        $b->execute(<<<'SQL'
COMMENT ON COLUMN address_search.region_code IS
'Региональный ключ из активной AS_ADM_HIERARCHY.REGIONCODE; не вычисляется из ОКАТО'
SQL);
        $b->execute(<<<'SQL'
CREATE INDEX IF NOT EXISTS idx_address_search_region_hierarchy
ON address_search USING BTREE (region_code, hierarchy_type)
SQL);
    }

    public function down(MigrationBuilder $b): void
    {
        $b->execute(<<<'SQL'
DO $migration$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM pg_class
        WHERE oid = 'address_search'::regclass
          AND relkind = 'p'
    ) THEN
        RAISE EXCEPTION
            'Cannot restore the legacy generated region_code after address_search partition cutover';
    END IF;
END
$migration$
SQL);
        $b->execute('DROP INDEX IF EXISTS idx_address_search_region_hierarchy');
        $b->execute('ALTER TABLE address_search DROP CONSTRAINT IF EXISTS chk_address_search_region_code');
        $b->execute('ALTER TABLE address_search DROP COLUMN IF EXISTS region_code');
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
}
