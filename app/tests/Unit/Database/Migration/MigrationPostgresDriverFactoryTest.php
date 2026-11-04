<?php

declare(strict_types=1);

namespace App\Tests\Unit\Database\Migration;

use App\Config\PostgresConfig;
use App\Database\Migration\MigrationPostgresDriverFactory;
use Testo\Assert;
use Testo\Test;

#[Test]
final class MigrationPostgresDriverFactoryTest
{
    public function testItConvertsTheApplicationDsnForPdoWithoutEmbeddingCredentials(): void
    {
        $driver = new MigrationPostgresDriverFactory()->create(new PostgresConfig(
            dsn: "host=postgres port=5433 user='app user' password='local secret' db=registry "
                . "application_name=geo-registry-migration sslmode=require options='-c statement_timeout=0'",
            poolSize: 10,
        ));

        Assert::same(
            $driver->getDsn(),
            'pgsql:host=postgres;port=5433;dbname=registry;sslmode=require;'
                . 'application_name=geo-registry-migration;options=-c statement_timeout=0',
        );
        Assert::same($driver->getUsername(), 'app user');
        Assert::same($driver->getPassword(), 'local secret');
        Assert::same($driver->getCharset(), 'UTF8');
        Assert::false(str_contains($driver->getDsn(), 'local secret'));
    }
}
