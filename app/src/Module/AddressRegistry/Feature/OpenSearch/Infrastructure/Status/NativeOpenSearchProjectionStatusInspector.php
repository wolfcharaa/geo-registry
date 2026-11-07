<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\OpenSearch\Infrastructure\Status;

use App\Infrastructure\OpenSearch\OpenSearchClient;
use App\Module\AddressRegistry\Feature\OpenSearch\Capability\OpenSearchProjectionFailed;
use App\Module\AddressRegistry\Feature\OpenSearch\Capability\ProjectionStatus\OpenSearchProjectionStatusInspector;
use App\Module\AddressRegistry\Feature\OpenSearch\Capability\ProjectionStatus\OpenSearchProjectionStatusReport;
use App\Module\AddressRegistry\Feature\OpenSearch\Infrastructure\Rebuild\PostgresOpenSearchProjectionSource;
use Throwable;

final readonly class NativeOpenSearchProjectionStatusInspector implements OpenSearchProjectionStatusInspector
{
    public function __construct(
        private PostgresOpenSearchProjectionSource $source,
        private OpenSearchClient $openSearch,
        private string $alias,
    ) {}

    public function inspect(): OpenSearchProjectionStatusReport
    {
        try {
            $health = $this->openSearch->health();
            $targets = $this->openSearch->aliasTargets($this->alias);
            $manifest = $this->source->manifest();
            $targetDocuments = count($targets) === 1
                ? $this->openSearch->countDocuments($targets[0])
                : null;
            $inactiveIndexes = array_values(array_diff(
                $this->openSearch->concreteIndexes($this->alias . '-v'),
                $targets,
            ));
            sort($inactiveIndexes);

            return new OpenSearchProjectionStatusReport(
                alias: $this->alias,
                clusterStatus: self::status($health),
                clusterTimedOut: self::boolean($health, 'timed_out'),
                nodes: self::integer($health, 'number_of_nodes'),
                dataNodes: self::integer($health, 'number_of_data_nodes'),
                aliasTargets: $targets,
                sourceDocuments: $manifest->documents,
                invalidRegionDocuments: $manifest->invalidRegionDocuments,
                targetDocuments: $targetDocuments,
                inactiveIndexes: $inactiveIndexes,
            );
        } catch (OpenSearchProjectionFailed $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new OpenSearchProjectionFailed(
                'OpenSearch status inspection failed: ' . $error->getMessage(),
                previous: $error,
            );
        }
    }

    /** @param array<string, mixed> $health */
    private static function status(array $health): string
    {
        $status = $health['status'] ?? null;
        if (!in_array($status, ['green', 'yellow', 'red'], true)) {
            throw new OpenSearchProjectionFailed('OpenSearch returned an invalid cluster status.');
        }

        return $status;
    }

    /** @param array<string, mixed> $values */
    private static function boolean(array $values, string $field): bool
    {
        $value = $values[$field] ?? null;
        if (!is_bool($value)) {
            throw new OpenSearchProjectionFailed("OpenSearch returned an invalid `{$field}` value.");
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private static function integer(array $values, string $field): int
    {
        $value = $values[$field] ?? null;
        if (!is_int($value) || $value < 0) {
            throw new OpenSearchProjectionFailed("OpenSearch returned an invalid `{$field}` value.");
        }

        return $value;
    }
}
