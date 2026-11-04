<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

final class M260903040000AddAddressSearchGeocodeMetadata implements RevertibleMigrationInterface, TransactionalMigrationInterface
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
            'ALTER TABLE address_search ADD COLUMN geocode_source varchar(32)',
            'ALTER TABLE address_search ADD COLUMN geocode_osm_type varchar(16)',
            'ALTER TABLE address_search ADD COLUMN geocode_osm_id bigint',
            'ALTER TABLE address_search ADD COLUMN geocode_display_name text',
            'CREATE INDEX idx_address_search_geocoded_at ON address_search USING BTREE (geocoded_at) WHERE geocoded_at IS NOT NULL',
            'CREATE INDEX idx_address_search_geocode_source ON address_search USING BTREE (geocode_source) WHERE geocode_source IS NOT NULL',
            'CREATE INDEX idx_address_search_geocode_osm ON address_search USING BTREE (geocode_osm_type, geocode_osm_id) WHERE geocode_osm_id IS NOT NULL',
        ];
    }

    /**
     * @return list<string>
     */
    private function downSql(): array
    {
        return [
            'DROP INDEX IF EXISTS idx_address_search_geocode_osm',
            'DROP INDEX IF EXISTS idx_address_search_geocode_source',
            'DROP INDEX IF EXISTS idx_address_search_geocoded_at',
            'ALTER TABLE address_search DROP COLUMN IF EXISTS geocode_display_name',
            'ALTER TABLE address_search DROP COLUMN IF EXISTS geocode_osm_id',
            'ALTER TABLE address_search DROP COLUMN IF EXISTS geocode_osm_type',
            'ALTER TABLE address_search DROP COLUMN IF EXISTS geocode_source',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function columnComments(): array
    {
        return [
            'geocode_source' => 'Источник координат для geo_lat/geo_lon: nominatim, внешний геокодер или ручная загрузка.',
            'geocode_osm_type' => 'Тип OSM-объекта, использованного Nominatim при геокодировании адреса.',
            'geocode_osm_id' => 'Идентификатор OSM-объекта, использованного Nominatim при геокодировании адреса.',
            'geocode_display_name' => 'Исходная display_name строка Nominatim, по которой можно проверить качество матча.',
        ];
    }
}
