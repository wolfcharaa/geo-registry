<?php

declare(strict_types=1);

namespace App\Tests\Integration\OpenSearch;

use App\Application\DependencyInjection\CliRuntimeModule;
use App\Application\Runtime\CliRuntime;
use App\Config\EnvironmentSnapshot;
use App\Infrastructure\Http\AmpHttpClientFactory;
use App\Infrastructure\OpenSearch\OpenSearchClient;
use App\Infrastructure\OpenSearch\OpenSearchClientFactory;
use App\Infrastructure\Postgres\PostgresPoolFactory;
use App\Tests\Support\Database\AddressRegistryParityFixture;
use App\Tests\Support\Database\IntegrationDatabase;
use LogicException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Test;
use Thesis\Dic;

#[Test]
final class OpenSearchProjectionRebuildIntegrationTest
{
    public function testItKeepsTheLiveAliasOnFailureAndCleansOnlyConfirmedInactiveIndexes(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $url = self::environment('INTEGRATION_OPENSEARCH_URL', 'http://127.0.0.1:59200');
        $alias = 'geo-registry-rebuild-' . bin2hex(random_bytes(5));
        $pool = PostgresPoolFactory::create($dsn, 1);
        $openSearch = self::client($url);
        $createdIndexes = [];

        try {
            IntegrationDatabase::resetSchema($pool);
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', ['--force-yes' => true]);
            AddressRegistryParityFixture::load($pool);

            [$exitCode, $output] = self::runCli($dsn, $url, $alias, [
                'command' => 'gar:address-search:rebuild',
                '--batch-size' => '2',
                '--no-ansi' => true,
            ]);
            Assert::same($exitCode, 0);
            Assert::true(str_contains($output, 'status=completed'));

            [$exitCode, $output] = self::runRebuild($dsn, $url, $alias);
            Assert::same($exitCode, 0);
            $firstIndex = self::completedTarget($output);
            $createdIndexes[] = $firstIndex;
            Assert::same($openSearch->aliasTargets($alias), [$firstIndex]);
            Assert::same($openSearch->countDocuments($firstIndex), 20);

            [$exitCode, $output] = self::runStatus($dsn, $url, $alias);
            Assert::same($exitCode, 0);
            Assert::true(str_contains($output, "alias name={$alias} targets={$firstIndex}"));
            Assert::true(str_contains($output, 'parity source=20 target=20 invalid_regions=0 status=ok'));
            Assert::true(str_contains($output, 'inactive count=0 indexes=none'));

            $levelRow = $pool->execute(<<<'SQL'
SELECT level
FROM address_search
WHERE object_id = :object_id AND hierarchy_type = 'adm'
ORDER BY region_code
LIMIT 1
SQL, ['object_id' => 770004])->fetchRow();
            $originalLevel = is_array($levelRow) ? (int) ($levelRow['level'] ?? 0) : 0;
            $pool->execute(<<<'SQL'
UPDATE address_search
SET level = 40000
WHERE object_id = :object_id AND hierarchy_type = 'adm'
SQL, ['object_id' => 770004]);

            [$exitCode, $output] = self::runRebuild($dsn, $url, $alias);
            Assert::same($exitCode, 1);
            Assert::true(str_contains($output, 'before alias switch'));
            $failedIndex = self::failedTarget($output);
            $createdIndexes[] = $failedIndex;
            Assert::same($openSearch->aliasTargets($alias), [$firstIndex]);
            Assert::true($openSearch->concreteIndexExists($failedIndex));

            $pool->execute(<<<'SQL'
UPDATE address_search
SET level = :level
WHERE object_id = :object_id AND hierarchy_type = 'adm'
SQL, ['level' => $originalLevel, 'object_id' => 770004]);

            [$exitCode, $output] = self::runRebuild($dsn, $url, $alias);
            Assert::same($exitCode, 0);
            $secondIndex = self::completedTarget($output);
            $createdIndexes[] = $secondIndex;
            Assert::false($secondIndex === $firstIndex);
            Assert::same($openSearch->aliasTargets($alias), [$secondIndex]);
            Assert::true($openSearch->concreteIndexExists($firstIndex));

            [$exitCode, $output] = self::runStatus($dsn, $url, $alias);
            Assert::same($exitCode, 0);
            Assert::true(str_contains($output, "alias name={$alias} targets={$secondIndex}"));
            $inactiveIndexes = [$failedIndex, $firstIndex];
            sort($inactiveIndexes);
            Assert::true(str_contains(
                $output,
                'inactive count=2 indexes=' . implode(',', $inactiveIndexes),
            ));

            [$exitCode] = self::runCleanup($dsn, $url, $alias, $firstIndex, 'wrong-confirmation');
            Assert::same($exitCode, 1);
            Assert::true($openSearch->concreteIndexExists($firstIndex));

            [$exitCode] = self::runCleanup($dsn, $url, $alias, $secondIndex, $secondIndex);
            Assert::same($exitCode, 1);
            Assert::same($openSearch->aliasTargets($alias), [$secondIndex]);

            [$exitCode, $output] = self::runCleanup($dsn, $url, $alias, $firstIndex, $firstIndex);
            Assert::same($exitCode, 0);
            Assert::true(str_contains($output, "status=deleted index={$firstIndex}"));
            Assert::false($openSearch->concreteIndexExists($firstIndex));

            [$exitCode] = self::runCleanup($dsn, $url, $alias, $failedIndex, $failedIndex);
            Assert::same($exitCode, 0);
            Assert::false($openSearch->concreteIndexExists($failedIndex));
        } finally {
            foreach (array_unique($createdIndexes) as $index) {
                if ($openSearch->concreteIndexExists($index)) {
                    $openSearch->deleteIndex($index);
                }
            }
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }

    /** @return array{int, string} */
    private static function runRebuild(string $dsn, string $url, string $alias): array
    {
        return self::runCli($dsn, $url, $alias, [
            'command' => 'opensearch:reindex',
            '--batch-size' => '2',
            '--concurrency' => '2',
            '--no-ansi' => true,
        ]);
    }

    /** @return array{int, string} */
    private static function runStatus(string $dsn, string $url, string $alias): array
    {
        return self::runCli($dsn, $url, $alias, [
            'command' => 'opensearch:status',
            '--no-ansi' => true,
        ]);
    }

    /** @return array{int, string} */
    private static function runCleanup(
        string $dsn,
        string $url,
        string $alias,
        string $index,
        string $confirmation,
    ): array {
        return self::runCli($dsn, $url, $alias, [
            'command' => 'opensearch:index:cleanup',
            '--index' => $index,
            '--confirm' => $confirmation,
            '--no-ansi' => true,
        ]);
    }

    /**
     * @param array<string, bool|string> $input
     * @return array{int, string}
     */
    private static function runCli(string $dsn, string $url, string $alias, array $input): array
    {
        $output = new BufferedOutput();
        $exitCode = Dic::run(
            new CliRuntimeModule(new EnvironmentSnapshot([
                'APP_NAME' => 'geo-registry-integration',
                'APP_VERSION' => 'test',
                'APP_USER_AGENT' => 'geo-registry-integration/test',
                'DATABASE_DSN' => $dsn,
                'OPENSEARCH_URL' => $url,
                'OPENSEARCH_INDEX' => $alias,
                'OPENSEARCH_TIMEOUT_SECONDS' => '10',
            ])),
            static fn(CliRuntime $runtime): int => $runtime->run(new ArrayInput($input), $output),
        );

        return [$exitCode, $output->fetch()];
    }

    private static function completedTarget(string $output): string
    {
        if (preg_match('/\btarget=([a-z0-9._-]+)/', $output, $matches) !== 1) {
            throw new LogicException("Completed OpenSearch target was not reported:\n{$output}");
        }

        return $matches[1];
    }

    private static function failedTarget(string $output): string
    {
        if (preg_match('/target `([a-z0-9._-]+)`/', $output, $matches) !== 1) {
            throw new LogicException("Failed OpenSearch target was not reported:\n{$output}");
        }

        return $matches[1];
    }

    private static function client(string $url): OpenSearchClient
    {
        return (new OpenSearchClientFactory(AmpHttpClientFactory::create(10)))->create(
            $url,
            'geo-registry-integration/test',
        );
    }

    private static function environment(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
