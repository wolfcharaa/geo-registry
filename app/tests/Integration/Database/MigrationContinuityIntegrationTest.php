<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use Amp\Postgres\PostgresConnectionPool;
use App\Application\DependencyInjection\CliRuntimeModule;
use App\Application\Runtime\CliRuntime;
use App\Config\EnvironmentSnapshot;
use App\Database\Migration\MigrationHistoryContract;
use App\Infrastructure\Postgres\PostgresPoolFactory;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Test;
use Thesis\Dic;

#[Test]
final class MigrationContinuityIntegrationTest
{
    public function testItBuildsTheCurrentSchemaFromZeroAndRollsBack(): void
    {
        $dsn = self::databaseDsn();
        $pool = PostgresPoolFactory::create($dsn, 1);

        try {
            self::resetDatabase($pool);

            self::runMigrationCommand($dsn, 'migrate:up', ['--force-yes' => true]);

            $history = [];
            $result = $pool->query('SELECT name FROM migration ORDER BY name');
            while (($row = $result->fetchRow()) !== null) {
                $name = $row['name'] ?? null;
                if (!is_string($name)) {
                    throw new RuntimeException('Migration history contains an invalid identifier.');
                }
                $history[] = $name;
            }

            Assert::same($history, MigrationHistoryContract::CLASSES);

            $schema = $pool->query(<<<'SQL'
SELECT
    to_regclass('public.reestr_objects')::text AS reestr_objects,
    to_regclass('public.address_search')::text AS address_search,
    to_regclass('public.address_search_build_progress')::text AS build_progress,
    to_regclass('public.gar_import_index_snapshots')::text AS import_snapshots,
    to_regclass('public.datamos_address_registry')::text AS datamos_registry,
    to_regclass('public.address_aliases')::text AS address_aliases,
    to_regclass('public.idx_object_params_denorm_type_object_update')::text AS denorm_index
SQL)->fetchRow();

            Assert::notNull($schema);
            Assert::same($schema['reestr_objects'] ?? null, 'reestr_objects');
            Assert::same($schema['address_search'] ?? null, 'address_search');
            Assert::same($schema['build_progress'] ?? null, 'address_search_build_progress');
            Assert::same($schema['import_snapshots'] ?? null, 'gar_import_index_snapshots');
            Assert::same($schema['datamos_registry'] ?? null, 'datamos_address_registry');
            Assert::same($schema['address_aliases'] ?? null, 'address_aliases');
            Assert::same($schema['denorm_index'] ?? null, 'idx_object_params_denorm_type_object_update');

            $extensions = [];
            $result = $pool->query(
                "SELECT extname FROM pg_extension WHERE extname IN ('hstore', 'pg_trgm', 'postgis') ORDER BY extname",
            );
            while (($row = $result->fetchRow()) !== null) {
                $extension = $row['extname'] ?? null;
                if (!is_string($extension)) {
                    throw new RuntimeException('PostgreSQL returned an invalid extension name.');
                }
                $extensions[] = $extension;
            }

            Assert::same($extensions, ['hstore', 'pg_trgm', 'postgis']);

            self::runMigrationCommand($dsn, 'migrate:down', [
                '--all' => true,
                '--force-yes' => true,
            ]);

            $rollback = $pool->query(<<<'SQL'
SELECT
    (SELECT count(*) FROM migration) AS history_count,
    to_regclass('public.reestr_objects')::text AS reestr_objects,
    to_regclass('public.address_search')::text AS address_search,
    to_regclass('public.address_aliases')::text AS address_aliases
SQL)->fetchRow();

            Assert::notNull($rollback);
            Assert::same((int) ($rollback['history_count'] ?? -1), 0);
            Assert::null($rollback['reestr_objects'] ?? null);
            Assert::null($rollback['address_search'] ?? null);
            Assert::null($rollback['address_aliases'] ?? null);
        } finally {
            self::resetDatabase($pool);
            $pool->close();
        }
    }

    /** @param array<string, bool> $options */
    private static function runMigrationCommand(string $dsn, string $command, array $options): void
    {
        $output = new BufferedOutput();
        $exitCode = Dic::run(
            new CliRuntimeModule(new EnvironmentSnapshot([
                'APP_NAME' => 'geo-registry-integration',
                'APP_VERSION' => 'test',
                'DATABASE_DSN' => $dsn,
            ])),
            static fn(CliRuntime $runtime): int => $runtime->run(
                new ArrayInput([
                    'command' => $command,
                    '--no-ansi' => true,
                    ...$options,
                ]),
                $output,
            ),
        );

        if ($exitCode !== 0) {
            throw new RuntimeException("{$command} failed with exit code {$exitCode}:\n{$output->fetch()}");
        }
    }

    private static function resetDatabase(PostgresConnectionPool $pool): void
    {
        $pool->query('DROP SCHEMA IF EXISTS public CASCADE');
        $pool->query('CREATE SCHEMA public');
    }

    private static function databaseDsn(): string
    {
        $dsn = getenv('INTEGRATION_DATABASE_DSN');

        return is_string($dsn) && $dsn !== ''
            ? $dsn
            : 'host=127.0.0.1 port=55432 user=integration password=integration-only db=integration';
    }
}
