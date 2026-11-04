<?php

declare(strict_types=1);

namespace App\Tests\Support\Database;

use Amp\Postgres\PostgresConnectionPool;
use RuntimeException;
use Throwable;

final class AddressRegistryParityFixture
{
    public const VERSION = '3';
    public const CHECKSUM = '5aa5118b7ab7e183d46a24b2f34fc79dc397838c20ae7483952f3ad54c455f73';

    private const STATEMENT_SEPARATOR = "\n-- fixture-statement\n";

    public static function load(PostgresConnectionPool $pool): void
    {
        $sql = self::source();
        $transaction = $pool->beginTransaction();

        try {
            foreach (explode(self::STATEMENT_SEPARATOR, trim($sql)) as $statement) {
                $transaction->query($statement);
            }

            $transaction->commit();
        } catch (Throwable $exception) {
            $transaction->rollback();

            throw $exception;
        }
    }

    public static function reset(PostgresConnectionPool $pool): void
    {
        $pool->query(<<<'SQL'
TRUNCATE TABLE
    address_search,
    municipal_reestr_hierarchy,
    administrative_reestr_hierarchy,
    houses,
    address_objects,
    reestr_objects,
    house_types,
    operation_types,
    address_object_types,
    object_levels
RESTART IDENTITY CASCADE
SQL);
    }

    public static function checksum(): string
    {
        return hash('sha256', self::source());
    }

    private static function source(): string
    {
        $sql = file_get_contents(dirname(__DIR__, 2) . '/Fixture/Database/address-registry-v3.sql');
        if (!is_string($sql)) {
            throw new RuntimeException('Unable to read the address registry parity fixture.');
        }

        $checksum = hash('sha256', $sql);
        if ($checksum !== self::CHECKSUM) {
            throw new RuntimeException("Parity fixture checksum mismatch: {$checksum}.");
        }

        return $sql;
    }

    private function __construct() {}
}
