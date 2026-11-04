<?php

declare(strict_types=1);

namespace App\Tests\Support\Database;

use Amp\Postgres\PostgresConnectionPool;
use App\Application\DependencyInjection\CliRuntimeModule;
use App\Application\Runtime\CliRuntime;
use App\Config\EnvironmentSnapshot;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Thesis\Dic;

final class IntegrationDatabase
{
    public static function dsn(): string
    {
        $dsn = getenv('INTEGRATION_DATABASE_DSN');

        return is_string($dsn) && $dsn !== ''
            ? $dsn
            : 'host=127.0.0.1 port=55432 user=integration password=integration-only db=integration application_name=geo-registry-integration';
    }

    public static function resetSchema(PostgresConnectionPool $pool): void
    {
        $identity = $pool->query(<<<'SQL'
SELECT
    current_database() AS database_name,
    current_setting('application_name') AS application_name
SQL)->fetchRow();

        if (
            ($identity['database_name'] ?? null) !== 'integration'
            || ($identity['application_name'] ?? null) !== 'geo-registry-integration'
        ) {
            throw new RuntimeException('Refusing to reset a database outside the isolated integration runtime.');
        }

        $pool->query('DROP SCHEMA IF EXISTS public CASCADE');
        $pool->query('CREATE SCHEMA public');
    }

    /** @param array<string, bool|int|string> $options */
    public static function runMigrationCommand(string $dsn, string $command, array $options): void
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

    private function __construct() {}
}
