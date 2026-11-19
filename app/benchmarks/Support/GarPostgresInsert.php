<?php

declare(strict_types=1);

namespace App\Benchmark\Support;

use PgSql\Connection;
use PgSql\Result;
use RuntimeException;
use Throwable;

/** Benchmark-only prepared and multi-row INSERT transport. */
final class GarPostgresInsert
{
    private const COLUMNS = 9;

    private const INSERT_PREFIX = <<<'SQL'
INSERT INTO gar_xml_copy_benchmark (
    id, object_id, change_id, change_id_end, type_id,
    value, update_date, start_date, end_date
) VALUES
SQL;

    /** @var array<int, string> */
    private array $multiRowSql = [];

    public function __construct(private readonly Connection $connection) {}

    /**
     * @param iterable<array-key, list<?string>> $rows
     *
     * @return array{
     *     rows: int,
     *     queries: int,
     *     batch_rows: int,
     *     parameter_bytes: int,
     *     begin_seconds: float,
     *     prepare_seconds: float,
     *     query_seconds: float,
     *     commit_seconds: float
     * }
     */
    public function insert(iterable $rows, int $batchRows): array
    {
        if ($batchRows < 1 || $batchRows * self::COLUMNS > 65_535) {
            throw new RuntimeException('GAR INSERT benchmark batch exceeds the PostgreSQL parameter limit.');
        }

        $rowCount = 0;
        $queries = 0;
        $parameterBytes = 0;
        $beginNanoseconds = 0.0;
        $prepareNanoseconds = 0.0;
        $queryNanoseconds = 0.0;
        $commitNanoseconds = 0.0;
        $transactionOpen = false;

        try {
            $startedAt = \hrtime(true);
            self::command($this->connection, 'BEGIN', 'Unable to begin GAR INSERT benchmark transaction.');
            $beginNanoseconds += \hrtime(true) - $startedAt;
            $transactionOpen = true;

            if ($batchRows === 1) {
                $startedAt = \hrtime(true);
                $prepared = \pg_prepare(
                    $this->connection,
                    'gar_benchmark_insert_one',
                    self::INSERT_PREFIX . ' ($1, $2, $3, $4, $5, $6, $7, $8, $9)',
                );
                if (!$prepared instanceof Result || \pg_result_status($prepared) !== PGSQL_COMMAND_OK) {
                    throw new RuntimeException('Unable to prepare single-row GAR INSERT benchmark statement.');
                }
                \pg_free_result($prepared);
                $prepareNanoseconds += \hrtime(true) - $startedAt;

                foreach ($rows as $values) {
                    self::assertRow($values);
                    $parameterBytes += self::parameterBytes($values);
                    $startedAt = \hrtime(true);
                    $inserted = \pg_execute($this->connection, 'gar_benchmark_insert_one', $values);
                    if (!$inserted instanceof Result || \pg_result_status($inserted) !== PGSQL_COMMAND_OK) {
                        throw new RuntimeException('Single-row GAR INSERT benchmark statement failed.');
                    }
                    \pg_free_result($inserted);
                    $queryNanoseconds += \hrtime(true) - $startedAt;
                    ++$rowCount;
                    ++$queries;
                }
            } else {
                $batch = [];
                foreach ($rows as $values) {
                    self::assertRow($values);
                    $parameterBytes += self::parameterBytes($values);
                    $batch[] = $values;
                    ++$rowCount;
                    if (\count($batch) < $batchRows) {
                        continue;
                    }

                    $queryNanoseconds += $this->insertBatch($batch);
                    ++$queries;
                    $batch = [];
                }
                if ($batch !== []) {
                    $queryNanoseconds += $this->insertBatch($batch);
                    ++$queries;
                }
            }

            $startedAt = \hrtime(true);
            self::command($this->connection, 'COMMIT', 'Unable to commit GAR INSERT benchmark transaction.');
            $commitNanoseconds += \hrtime(true) - $startedAt;
        } catch (Throwable $failure) {
            if ($transactionOpen) {
                \pg_query($this->connection, 'ROLLBACK');
            }

            throw $failure;
        }

        return [
            'rows' => $rowCount,
            'queries' => $queries,
            'batch_rows' => $batchRows,
            'parameter_bytes' => $parameterBytes,
            'begin_seconds' => $beginNanoseconds / 1_000_000_000,
            'prepare_seconds' => $prepareNanoseconds / 1_000_000_000,
            'query_seconds' => $queryNanoseconds / 1_000_000_000,
            'commit_seconds' => $commitNanoseconds / 1_000_000_000,
        ];
    }

    /** @param non-empty-list<list<?string>> $batch */
    private function insertBatch(array $batch): float
    {
        $rowCount = \count($batch);
        $parameters = [];
        foreach ($batch as $values) {
            foreach ($values as $value) {
                $parameters[] = $value;
            }
        }

        $startedAt = \hrtime(true);
        $inserted = \pg_query_params($this->connection, $this->multiRowSql($rowCount), $parameters);
        if (!$inserted instanceof Result || \pg_result_status($inserted) !== PGSQL_COMMAND_OK) {
            throw new RuntimeException('Multi-row GAR INSERT benchmark statement failed.');
        }
        \pg_free_result($inserted);

        return (float) (\hrtime(true) - $startedAt);
    }

    private function multiRowSql(int $rows): string
    {
        if (isset($this->multiRowSql[$rows])) {
            return $this->multiRowSql[$rows];
        }

        $parameter = 1;
        $groups = [];
        for ($row = 0; $row < $rows; ++$row) {
            $placeholders = [];
            for ($column = 0; $column < self::COLUMNS; ++$column) {
                $placeholders[] = '$' . $parameter;
                ++$parameter;
            }
            $groups[] = '(' . \implode(', ', $placeholders) . ')';
        }

        return $this->multiRowSql[$rows] = self::INSERT_PREFIX . ' ' . \implode(', ', $groups);
    }

    /** @param list<?string> $values */
    private static function assertRow(array $values): void
    {
        if (\count($values) !== self::COLUMNS) {
            throw new RuntimeException('GAR INSERT benchmark row must contain exactly nine fields.');
        }
    }

    /** @param list<?string> $values */
    private static function parameterBytes(array $values): int
    {
        $bytes = 0;
        foreach ($values as $value) {
            if ($value !== null) {
                $bytes += \strlen($value);
            }
        }

        return $bytes;
    }

    private static function command(Connection $connection, string $sql, string $failure): void
    {
        $result = \pg_query($connection, $sql);
        if (!$result instanceof Result || \pg_result_status($result) !== PGSQL_COMMAND_OK) {
            throw new RuntimeException($failure);
        }
        \pg_free_result($result);
    }
}
