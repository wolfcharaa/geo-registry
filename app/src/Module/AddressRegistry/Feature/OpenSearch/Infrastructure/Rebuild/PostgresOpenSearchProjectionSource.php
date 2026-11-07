<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\OpenSearch\Infrastructure\Rebuild;

use Amp\Postgres\PostgresConnectionPool;
use App\Module\AddressRegistry\Feature\OpenSearch\Capability\OpenSearchProjectionFailed;
use App\Module\AddressRegistry\Infrastructure\Postgres\PostgresAddressDocumentHydrator;
use Throwable;

final readonly class PostgresOpenSearchProjectionSource
{
    private const string MANIFEST_SQL = <<<'SQL'
SELECT
    count(*)::bigint AS document_count,
    count(*) FILTER (WHERE region_code IS NULL OR region_code NOT BETWEEN 1 AND 99)::bigint AS invalid_region_count
FROM address_search
SQL;

    public function __construct(
        private PostgresConnectionPool $postgres,
        private PostgresAddressDocumentHydrator $hydrator,
    ) {}

    public function openSnapshot(): PostgresOpenSearchProjectionSnapshot
    {
        $transaction = $this->postgres->beginTransaction();

        try {
            $transaction->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $manifest = self::manifestFromRow($transaction->query(self::MANIFEST_SQL)->fetchRow());

            return new PostgresOpenSearchProjectionSnapshot(
                transaction: $transaction,
                hydrator: $this->hydrator,
                manifest: $manifest,
            );
        } catch (Throwable $error) {
            if ($transaction->isActive()) {
                $transaction->rollback();
            }

            throw $error;
        }
    }

    public function manifest(): OpenSearchProjectionManifest
    {
        return self::manifestFromRow($this->postgres->query(self::MANIFEST_SQL)->fetchRow());
    }

    /** @param array<array-key, mixed>|null $row */
    private static function manifestFromRow(?array $row): OpenSearchProjectionManifest
    {
        if ($row === null) {
            throw new OpenSearchProjectionFailed('PostgreSQL did not return an OpenSearch projection manifest.');
        }

        return new OpenSearchProjectionManifest(
            documents: self::integer($row, 'document_count'),
            invalidRegionDocuments: self::integer($row, 'invalid_region_count'),
        );
    }

    /** @param array<array-key, mixed> $row */
    private static function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new OpenSearchProjectionFailed("PostgreSQL returned an invalid manifest field `{$field}`.");
    }
}
