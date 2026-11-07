<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\AddressRegistry;

use Amp\NullCancellation;
use App\Application\DependencyInjection\CliRuntimeModule;
use App\Application\Runtime\CliRuntime;
use App\Config\EnvironmentSnapshot;
use App\Infrastructure\Postgres\PostgresPoolFactory;
use App\Module\AddressRegistry\Capability\AddressHierarchy;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressCriteria;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressMatch;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchProfile;
use App\Module\AddressRegistry\Infrastructure\Postgres\PostgresAddressDocumentHydrator;
use App\Module\AddressRegistry\Infrastructure\Postgres\PostgresSearchAddressReader;
use App\Tests\Support\Database\IntegrationDatabase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Test;
use Thesis\Dic;

#[Test]
final class AddressRegistryDataFlowSmokeIntegrationTest
{
    public function testItImportsBuildsAndSearchesTheRepresentativeGarFixture(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 1);
        $source = dirname(__DIR__, 3) . '/Fixture/Gar/representative';

        try {
            IntegrationDatabase::resetSchema($pool);
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', ['--force-yes' => true]);

            [$importExitCode, $importOutput] = self::runCli($dsn, [
                'command' => 'gar:import',
                'source' => $source,
                '--batch-size' => '2',
                '--workers' => '2',
                '--no-ansi' => true,
            ]);
            Assert::same($importExitCode, 0);
            Assert::true(str_contains($importOutput, 'status=completed'));
            Assert::true(str_contains($importOutput, 'rows=9'));

            [$rebuildExitCode, $rebuildOutput] = self::runCli($dsn, [
                'command' => 'gar:address-search:rebuild',
                '--batch-size' => '2',
                '--no-ansi' => true,
            ]);
            Assert::same($rebuildExitCode, 0);
            Assert::true(str_contains($rebuildOutput, 'status=completed'));

            $matches = (new PostgresSearchAddressReader(
                $pool,
                new PostgresAddressDocumentHydrator(),
            ))->search(new SearchAddressCriteria(
                query: 'Тестовый регион',
                hierarchy: AddressHierarchy::Administrative,
                profile: SearchProfile::Search,
                limit: 5,
                regionCode: 77,
            ), new NullCancellation());

            Assert::same(array_map(
                static fn(SearchAddressMatch $match): int => $match->document->objectId,
                $matches,
            ), [770001]);
            $match = $matches[0] ?? null;
            Assert::notNull($match);
            Assert::same($match->backend, 'postgres');
        } finally {
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }

    /**
     * @param array<string, bool|string> $input
     * @return array{int, string}
     */
    private static function runCli(string $dsn, array $input): array
    {
        $output = new BufferedOutput();
        $exitCode = Dic::run(
            new CliRuntimeModule(new EnvironmentSnapshot([
                'APP_NAME' => 'geo-registry-integration',
                'APP_VERSION' => 'test',
                'DATABASE_DSN' => $dsn,
            ])),
            static fn(CliRuntime $runtime): int => $runtime->run(new ArrayInput($input), $output),
        );

        return [$exitCode, $output->fetch()];
    }
}
