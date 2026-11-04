<?php

declare(strict_types=1);

namespace App\Tests\Unit\Database\Migration;

use App\Database\Migration\MigrationHistoryContract;
use ReflectionClass;
use RuntimeException;
use Testo\Assert;
use Testo\Test;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

#[Test]
final class MigrationHistoryContractTest
{
    public function testItPreservesAllLegacyHistoryIdentifiers(): void
    {
        $files = glob(dirname(__DIR__, 4) . '/src/Database/Migration/M[0-9]*.php');
        if ($files === false) {
            throw new RuntimeException('Unable to read migration source directory.');
        }

        $actual = array_map(
            static fn(string $file): string => 'App\\Database\\Migration\\' . pathinfo($file, PATHINFO_FILENAME),
            $files,
        );
        sort($actual);

        Assert::same($actual, MigrationHistoryContract::CLASSES);

        $transactional = 0;
        foreach (MigrationHistoryContract::CLASSES as $migration) {
            $reflection = new ReflectionClass($migration);

            Assert::true($reflection->implementsInterface(RevertibleMigrationInterface::class));
            Assert::same($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0, 0);

            if ($reflection->implementsInterface(TransactionalMigrationInterface::class)) {
                ++$transactional;
            }
        }

        Assert::same($transactional, 22);
    }
}
