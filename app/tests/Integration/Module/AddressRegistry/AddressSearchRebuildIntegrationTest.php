<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\AddressRegistry;

use App\Application\DependencyInjection\CliRuntimeModule;
use App\Application\Runtime\CliRuntime;
use App\Config\EnvironmentSnapshot;
use App\Infrastructure\Postgres\PostgresPoolFactory;
use App\Tests\Support\Database\AddressRegistryParityFixture;
use App\Tests\Support\Database\IntegrationDatabase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Test;
use Thesis\Dic;

#[Test]
final class AddressSearchRebuildIntegrationTest
{
    public function testItRecoversReadyShadowAndSwitchesToThePartitionedReadModel(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 1);

        try {
            IntegrationDatabase::resetSchema($pool);
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', ['--force-yes' => true]);
            AddressRegistryParityFixture::load($pool);
            $pool->query(<<<'SQL'
UPDATE address_search
SET address_string = 'legacy sentinel'
WHERE object_id = 770004 AND hierarchy_type = 'adm'
SQL);

            [$exitCode, $output] = self::runRebuild($dsn, true);
            Assert::same($exitCode, 0);
            Assert::true(str_contains($output, 'status=ready'));
            Assert::true(str_contains($output, 'tasks=4/4'));

            $ready = $pool->query(<<<'SQL'
SELECT
    (SELECT address_string FROM address_search WHERE object_id = 770004 AND hierarchy_type = 'adm') AS active_address,
    (SELECT status FROM address_search_rebuild_operations ORDER BY id DESC LIMIT 1) AS operation_status,
    (SELECT relkind FROM pg_class WHERE oid = 'address_search'::regclass) AS active_relkind,
    (SELECT relkind FROM pg_class WHERE oid = 'address_search_build_1'::regclass) AS shadow_relkind,
    (SELECT count(*) FROM pg_inherits WHERE inhparent = 'address_search_build_1'::regclass) AS partitions
SQL)->fetchRow();
            Assert::notNull($ready);
            Assert::same($ready['active_address'] ?? null, 'legacy sentinel');
            Assert::same($ready['operation_status'] ?? null, 'ready');
            Assert::same($ready['active_relkind'] ?? null, 'r');
            Assert::same($ready['shadow_relkind'] ?? null, 'p');
            Assert::same((int) ($ready['partitions'] ?? -1), 2);

            [$exitCode, $output] = self::runRebuild($dsn, false);
            Assert::same($exitCode, 0);
            Assert::true(str_contains($output, 'status=completed'));
            Assert::true(str_contains($output, 'resumed=yes'));

            $completed = $pool->query(<<<'SQL'
SELECT
    (SELECT address_string FROM address_search WHERE object_id = 770004 AND hierarchy_type = 'adm') AS active_address,
    (SELECT count(*) FROM address_search) AS documents,
    (SELECT relkind FROM pg_class WHERE oid = 'address_search'::regclass) AS active_relkind,
    to_regclass('public.address_search_previous_1')::text AS previous_table,
    (SELECT status FROM address_search_rebuild_operations WHERE id = 1) AS operation_status,
    (SELECT attempt FROM address_search_rebuild_operations WHERE id = 1) AS attempt,
    (SELECT missing_rows FROM address_search_rebuild_operations WHERE id = 1) AS missing_rows
SQL)->fetchRow();
            Assert::notNull($completed);
            Assert::false(($completed['active_address'] ?? null) === 'legacy sentinel');
            Assert::same((int) ($completed['documents'] ?? -1), 20);
            Assert::same($completed['active_relkind'] ?? null, 'p');
            Assert::null($completed['previous_table'] ?? null);
            Assert::same($completed['operation_status'] ?? null, 'completed');
            Assert::same((int) ($completed['attempt'] ?? -1), 2);
            Assert::same((int) ($completed['missing_rows'] ?? -1), 0);

            $plan = '';
            $result = $pool->query(<<<'SQL'
EXPLAIN (COSTS OFF)
SELECT object_id
FROM address_search
WHERE region_code = 77 AND hierarchy_type = 'adm'
SQL);
            while (($row = $result->fetchRow()) !== null) {
                $line = $row['QUERY PLAN'] ?? null;
                if (is_string($line)) {
                    $plan .= $line;
                }
            }
            Assert::true(str_contains($plan, 'address_search_build_1_r77'));
            Assert::false(str_contains($plan, 'address_search_build_1_r78'));
        } finally {
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }

    /** @return array{int, string} */
    private static function runRebuild(string $dsn, bool $buildOnly): array
    {
        $output = new BufferedOutput();
        $input = [
            'command' => 'gar:address-search:rebuild',
            '--batch-size' => '2',
            '--no-ansi' => true,
        ];
        if ($buildOnly) {
            $input['--build-only'] = true;
        }

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
