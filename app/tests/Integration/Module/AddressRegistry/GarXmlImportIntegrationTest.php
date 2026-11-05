<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\AddressRegistry;

use App\Application\DependencyInjection\CliRuntimeModule;
use App\Application\Runtime\CliRuntime;
use App\Config\EnvironmentSnapshot;
use App\Infrastructure\Postgres\PostgresPoolFactory;
use App\Tests\Support\Database\IntegrationDatabase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Test;
use Thesis\Dic;

#[Test]
final class GarXmlImportIntegrationTest
{
    public function testItImportsResumesAndRestartsThroughAtomicCopyCheckpoints(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 1);
        $representative = dirname(__DIR__, 3) . '/Fixture/Gar/representative';
        $partial = dirname(__DIR__, 3) . '/Fixture/Gar/partial';

        try {
            IntegrationDatabase::resetSchema($pool);
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', ['--force-yes' => true]);

            [$exitCode, $output] = self::runImport($dsn, $representative, false);
            Assert::same($exitCode, 0);
            Assert::true(str_contains($output, 'status=completed'));
            Assert::true(str_contains($output, 'files=9/9'));
            Assert::true(str_contains($output, 'rows=9'));
            Assert::true(str_contains($output, 'workers=2'));

            $imported = $pool->query(<<<'SQL'
SELECT
    (SELECT count(*) FROM address_objects WHERE reestr_object_id = 770001) AS address_objects,
    (SELECT addr_type_id FROM address_objects WHERE reestr_object_id = 770001) AS addr_type_id,
    (SELECT orgname FROM normative_docs WHERE id = 3851715) AS normative_doc_orgname,
    (SELECT path_arr::text FROM administrative_reestr_hierarchy WHERE object_id = 770001) AS path_arr,
    (SELECT status FROM gar_import_operations ORDER BY id DESC LIMIT 1) AS operation_status,
    (SELECT count(*) FROM gar_import_files WHERE status = 'completed') AS completed_files
SQL)->fetchRow();
            Assert::notNull($imported);
            Assert::same((int) ($imported['address_objects'] ?? -1), 1);
            Assert::same((int) ($imported['addr_type_id'] ?? -1), 1);
            Assert::same($imported['normative_doc_orgname'] ?? null, '');
            Assert::same($imported['path_arr'] ?? null, '{770001}');
            Assert::same($imported['operation_status'] ?? null, 'completed');
            Assert::same((int) ($imported['completed_files'] ?? -1), 9);

            [$exitCode, $output] = self::runImport($dsn, $representative, false);
            Assert::same($exitCode, 0);
            Assert::true(str_contains($output, 'status=already-completed'));

            [$exitCode] = self::runImport($dsn, $partial, false);
            Assert::same($exitCode, 1);
            $failed = $pool->query(<<<'SQL'
SELECT id, status, attempt, rows_processed
FROM gar_import_operations
WHERE source_path LIKE '%/partial'
ORDER BY id DESC
LIMIT 1
SQL)->fetchRow();
            Assert::notNull($failed);
            Assert::same($failed['status'] ?? null, 'failed');
            Assert::same((int) ($failed['attempt'] ?? -1), 1);
            Assert::same((int) ($failed['rows_processed'] ?? -1), 1);

            [$exitCode] = self::runImport($dsn, $partial, false);
            Assert::same($exitCode, 1);
            $resumed = $pool->query(<<<'SQL'
SELECT id, status, attempt, rows_processed
FROM gar_import_operations
WHERE source_path LIKE '%/partial'
ORDER BY id DESC
LIMIT 1
SQL)->fetchRow();
            Assert::notNull($resumed);
            Assert::same($resumed['id'] ?? null, $failed['id'] ?? null);
            Assert::same((int) ($resumed['attempt'] ?? -1), 2);
            Assert::same((int) ($resumed['rows_processed'] ?? -1), 1);

            [$exitCode] = self::runImport($dsn, $partial, true);
            Assert::same($exitCode, 1);
            $restart = $pool->query(<<<'SQL'
SELECT
    count(*) AS operations,
    count(*) FILTER (WHERE status = 'abandoned') AS abandoned,
    count(*) FILTER (WHERE status = 'failed') AS failed,
    max(rows_processed) AS max_rows,
    (SELECT count(*) FROM pg_class WHERE relname = 'gar_import_stage' AND relpersistence = 't') AS staging_tables
FROM gar_import_operations
WHERE source_path LIKE '%/partial'
SQL)->fetchRow();
            Assert::notNull($restart);
            Assert::same((int) ($restart['operations'] ?? -1), 2);
            Assert::same((int) ($restart['abandoned'] ?? -1), 1);
            Assert::same((int) ($restart['failed'] ?? -1), 1);
            Assert::same((int) ($restart['max_rows'] ?? -1), 1);
            Assert::same((int) ($restart['staging_tables'] ?? -1), 0);
        } finally {
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }

    /** @return array{int, string} */
    private static function runImport(string $dsn, string $source, bool $restart): array
    {
        $output = new BufferedOutput();
        $input = [
            'command' => 'gar:import',
            'source' => $source,
            '--batch-size' => '1',
            '--workers' => '2',
            '--no-ansi' => true,
        ];
        if ($restart) {
            $input['--restart'] = true;
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
