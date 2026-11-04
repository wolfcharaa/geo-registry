<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

final class M260902050000EnableGeoExtensions implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute('CREATE EXTENSION IF NOT EXISTS hstore');
        $b->execute('CREATE EXTENSION IF NOT EXISTS postgis');
        $b->execute("COMMENT ON EXTENSION hstore IS 'Key-value storage used by OSM/Nominatim-derived attributes'");
        $b->execute("COMMENT ON EXTENSION postgis IS 'Geospatial types and indexes for OSM/Nominatim-derived data'");
    }

    public function down(MigrationBuilder $b): void
    {
        $b->execute('DROP EXTENSION IF EXISTS postgis');
        $b->execute('DROP EXTENSION IF EXISTS hstore');
    }
}
