<?php

declare(strict_types=1);

namespace App\Tests\Support\Database;

use Amp\Postgres\PostgresConnectionPool;
use RuntimeException;
use Throwable;

final class AddressRegistryParityFixture
{
    public const VERSION = '1';
    public const CHECKSUM = '35cd4f6215519a5777613f5bdb8b6ea8665a2a3335535a71c2c2f7ec57cf122c';

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
        $sql = file_get_contents(dirname(__DIR__, 2) . '/Fixture/Database/address-registry-v1.sql');
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
