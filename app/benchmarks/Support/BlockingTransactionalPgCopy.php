<?php

declare(strict_types=1);

namespace App\Benchmark\Support;

use PgSql\Connection;
use PgSql\Result;
use RuntimeException;
use Throwable;

/**
 * Benchmark-only blocking COPY protocol with bounded transaction payloads.
 *
 * Transport chunks remain independent from transaction boundaries. Each
 * completed COPY and its ledger marker are committed atomically.
 */
final readonly class BlockingTransactionalPgCopy
{
    public function __construct(private Connection $connection) {}

    /**
     * @param iterable<array-key, string> $chunks
     *
     * @return array{
     *     transactions: int,
     *     committed_bytes: int,
     *     wire_bytes: int,
     *     max_transaction_bytes: int,
     *     begin_seconds: float,
     *     copy_seconds: float,
     *     ledger_seconds: float,
     *     commit_seconds: float,
     *     readable_waits: 0,
     *     writable_waits: 0,
     *     copy_backpressure: 0
     * }
     */
    public function copy(
        string $copySql,
        iterable $chunks,
        int $transactionBytes,
        string $streamPrefix = '',
        string $streamSuffix = '',
    ): array {
        if ($transactionBytes < 1) {
            throw new RuntimeException('PostgreSQL COPY transaction boundary must be positive.');
        }

        $transactions = 0;
        $committedBytes = 0;
        $wireBytes = 0;
        $maxTransactionBytes = 0;
        $currentBytes = 0;
        $transactionOpen = false;
        $beginNanoseconds = 0.0;
        $copyNanoseconds = 0.0;
        $ledgerNanoseconds = 0.0;
        $commitNanoseconds = 0.0;

        try {
            foreach ($chunks as $chunk) {
                if (
                    $transactionOpen
                    && $currentBytes > 0
                    && $currentBytes + \strlen($chunk) > $transactionBytes
                ) {
                    $finished = $this->finishTransaction(
                        $transactions,
                        $committedBytes,
                        $wireBytes,
                        $maxTransactionBytes,
                        $currentBytes,
                        $copyNanoseconds,
                        $ledgerNanoseconds,
                        $commitNanoseconds,
                        $streamSuffix,
                    );
                    $transactionOpen = false;
                    $transactions = $finished['transactions'];
                    $committedBytes = $finished['committed_bytes'];
                    $wireBytes = $finished['wire_bytes'];
                    $maxTransactionBytes = $finished['max_transaction_bytes'];
                    $currentBytes = 0;
                    $copyNanoseconds = $finished['copy_nanoseconds'];
                    $ledgerNanoseconds = $finished['ledger_nanoseconds'];
                    $commitNanoseconds = $finished['commit_nanoseconds'];
                }

                if (!$transactionOpen) {
                    $startedAt = \hrtime(true);
                    self::command($this->connection, 'BEGIN', 'Unable to begin PostgreSQL COPY transaction.');
                    $beginNanoseconds += \hrtime(true) - $startedAt;

                    $startedAt = \hrtime(true);
                    $copyIn = \pg_query($this->connection, $copySql);
                    if (!$copyIn instanceof Result || \pg_result_status($copyIn) !== PGSQL_COPY_IN) {
                        throw new RuntimeException('PostgreSQL did not enter transactional COPY IN mode.');
                    }
                    \pg_free_result($copyIn);
                    $copyNanoseconds += \hrtime(true) - $startedAt;
                    $transactionOpen = true;

                    if ($streamPrefix !== '') {
                        $startedAt = \hrtime(true);
                        if (\pg_put_copy_data($this->connection, $streamPrefix) !== 1) {
                            throw new RuntimeException('Unable to send PostgreSQL COPY stream prefix.');
                        }
                        $copyNanoseconds += \hrtime(true) - $startedAt;
                        $wireBytes += \strlen($streamPrefix);
                    }
                }

                $startedAt = \hrtime(true);
                if (\pg_put_copy_data($this->connection, $chunk) !== 1) {
                    throw new RuntimeException('Unable to send transactional PostgreSQL COPY data.');
                }
                $copyNanoseconds += \hrtime(true) - $startedAt;
                $currentBytes += \strlen($chunk);
                $wireBytes += \strlen($chunk);
            }

            if ($transactionOpen) {
                $finished = $this->finishTransaction(
                    $transactions,
                    $committedBytes,
                    $wireBytes,
                    $maxTransactionBytes,
                    $currentBytes,
                    $copyNanoseconds,
                    $ledgerNanoseconds,
                    $commitNanoseconds,
                    $streamSuffix,
                );
                $transactions = $finished['transactions'];
                $committedBytes = $finished['committed_bytes'];
                $wireBytes = $finished['wire_bytes'];
                $maxTransactionBytes = $finished['max_transaction_bytes'];
                $copyNanoseconds = $finished['copy_nanoseconds'];
                $ledgerNanoseconds = $finished['ledger_nanoseconds'];
                $commitNanoseconds = $finished['commit_nanoseconds'];
            }
        } catch (Throwable $failure) {
            if ($transactionOpen) {
                \pg_query($this->connection, 'ROLLBACK');
            }

            throw $failure;
        }

        return [
            'transactions' => $transactions,
            'committed_bytes' => $committedBytes,
            'wire_bytes' => $wireBytes,
            'max_transaction_bytes' => $maxTransactionBytes,
            'begin_seconds' => $beginNanoseconds / 1_000_000_000,
            'copy_seconds' => $copyNanoseconds / 1_000_000_000,
            'ledger_seconds' => $ledgerNanoseconds / 1_000_000_000,
            'commit_seconds' => $commitNanoseconds / 1_000_000_000,
            'readable_waits' => 0,
            'writable_waits' => 0,
            'copy_backpressure' => 0,
        ];
    }

    /**
     * @return array{
     *     transactions: int,
     *     committed_bytes: int,
     *     wire_bytes: int,
     *     max_transaction_bytes: int,
     *     copy_nanoseconds: float,
     *     ledger_nanoseconds: float,
     *     commit_nanoseconds: float
     * }
     */
    private function finishTransaction(
        int $transactions,
        int $committedBytes,
        int $wireBytes,
        int $maxTransactionBytes,
        int $currentBytes,
        float $copyNanoseconds,
        float $ledgerNanoseconds,
        float $commitNanoseconds,
        string $streamSuffix,
    ): array {
        if ($currentBytes < 1) {
            throw new RuntimeException('PostgreSQL COPY transaction has no payload to commit.');
        }

        $startedAt = \hrtime(true);
        if ($streamSuffix !== '') {
            if (\pg_put_copy_data($this->connection, $streamSuffix) !== 1) {
                throw new RuntimeException('Unable to send PostgreSQL COPY stream suffix.');
            }
            $wireBytes += \strlen($streamSuffix);
        }
        if (\pg_put_copy_end($this->connection) !== 1) {
            throw new RuntimeException('Unable to finish transactional PostgreSQL COPY data.');
        }
        $completed = \pg_get_result($this->connection);
        if (!$completed instanceof Result || \pg_result_status($completed) !== PGSQL_COMMAND_OK) {
            throw new RuntimeException('Transactional PostgreSQL COPY failed.');
        }
        \pg_free_result($completed);
        $copyNanoseconds += \hrtime(true) - $startedAt;

        ++$transactions;
        $startedAt = \hrtime(true);
        $ledger = \pg_query_params(
            $this->connection,
            'INSERT INTO gar_xml_copy_ledger (sequence, encoded_bytes) VALUES ($1, $2)',
            [(string) $transactions, (string) $currentBytes],
        );
        if (!$ledger instanceof Result || \pg_result_status($ledger) !== PGSQL_COMMAND_OK) {
            throw new RuntimeException('Unable to write PostgreSQL COPY transaction ledger.');
        }
        \pg_free_result($ledger);
        $ledgerNanoseconds += \hrtime(true) - $startedAt;

        $startedAt = \hrtime(true);
        self::command($this->connection, 'COMMIT', 'Unable to commit PostgreSQL COPY transaction.');
        $commitNanoseconds += \hrtime(true) - $startedAt;

        return [
            'transactions' => $transactions,
            'committed_bytes' => $committedBytes + $currentBytes,
            'wire_bytes' => $wireBytes,
            'max_transaction_bytes' => \max($maxTransactionBytes, $currentBytes),
            'copy_nanoseconds' => $copyNanoseconds,
            'ledger_nanoseconds' => $ledgerNanoseconds,
            'commit_nanoseconds' => $commitNanoseconds,
        ];
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
