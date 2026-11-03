<?php

declare(strict_types=1);

namespace App\Tests\Integration\Postgres;

use App\Infrastructure\Postgres\PostgresPoolFactory;
use Testo\Assert;
use Testo\Test;

#[Test]
final class PostgresPoolIntegrationTest
{
    public function testItConnectsToTheEphemeralApplicationDatabase(): void
    {
        $pool = PostgresPoolFactory::create(
            self::environment(
                'INTEGRATION_DATABASE_DSN',
                'host=127.0.0.1 port=55432 user=integration password=integration-only db=integration',
            ),
            2,
        );

        try {
            $row = $pool->query('SELECT current_database() AS database_name, 1 AS ready')->fetchRow();

            Assert::notNull($row);
            Assert::same($row['database_name'] ?? null, 'integration');
            Assert::same((int) ($row['ready'] ?? 0), 1);
        } finally {
            $pool->close();
        }
    }

    private static function environment(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
