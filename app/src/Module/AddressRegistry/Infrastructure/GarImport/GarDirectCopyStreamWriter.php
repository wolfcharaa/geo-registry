<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use PgSql\Connection;
use PgSql\Result;
use Throwable;

/** Streams encoded rows into bounded atomic COPY + range-ledger transactions. */
final readonly class GarDirectCopyStreamWriter
{
    private GarDirectCopyRangeLedger $ledger;

    public function __construct(
        private Connection $connection,
        private int $transactionBytes,
        private int $transportBytes = 1024 * 1024,
    ) {
        if ($this->transportBytes < 1 || $this->transactionBytes < $this->transportBytes) {
            throw new GarImportFailed('GAR streaming COPY boundaries are invalid.');
        }

        $this->ledger = new GarDirectCopyRangeLedger($this->connection);
    }

    /**
     * @param iterable<int, string> $rows Row number => encoded COPY-text row.
     */
    public function write(
        int $operationId,
        string $fileKey,
        string $copyTarget,
        iterable $rows,
        int $expectedFirstRow,
        int $expectedLastRow,
        ?GarFusedRangeProfile $profile = null,
    ): GarDirectCopyStreamResult {
        if ($expectedFirstRow < 1 || $expectedLastRow < $expectedFirstRow) {
            throw new GarImportFailed('GAR streaming COPY expected range is invalid.');
        }

        $expectedRow = $expectedFirstRow;
        $segmentFirstRow = $expectedFirstRow;
        $segmentLastRow = $expectedFirstRow - 1;
        $segmentRows = 0;
        $segmentBytes = 0;
        $totalRows = 0;
        $totalBytes = 0;
        $transactions = 0;
        $chunk = '';
        $transactionOpen = false;
        $copyOpen = false;

        try {
            foreach ($rows as $rowNumber => $encoded) {
                if ($rowNumber !== $expectedRow || $encoded === '') {
                    throw new GarImportFailed('GAR streaming COPY received a non-contiguous or empty encoded row.');
                }
                $encodedBytes = \strlen($encoded);

                if ($segmentRows > 0 && $segmentBytes + $encodedBytes > $this->transactionBytes) {
                    $this->sendChunk($chunk, $profile);
                    $this->endCopy($profile);
                    $copyOpen = false;
                    $this->assertCopySucceeded($profile);
                    $this->commitSegment(
                        $operationId,
                        $fileKey,
                        $segmentFirstRow,
                        $segmentLastRow,
                        $segmentRows,
                        $segmentBytes,
                        $profile,
                    );
                    $transactionOpen = false;
                    ++$transactions;
                    $segmentFirstRow = $rowNumber;
                    $segmentRows = 0;
                    $segmentBytes = 0;
                }

                if (!$transactionOpen) {
                    $transactionOpen = true;
                    $this->beginCopy($copyTarget, $profile);
                    $copyOpen = true;
                }
                if ($chunk !== '' && \strlen($chunk) + $encodedBytes > $this->transportBytes) {
                    $this->sendChunk($chunk, $profile);
                }

                $chunk .= $encoded;
                $segmentLastRow = $rowNumber;
                ++$segmentRows;
                $segmentBytes += $encodedBytes;
                ++$totalRows;
                $totalBytes += $encodedBytes;
                ++$expectedRow;
            }

            if ($totalRows < 1 || $expectedRow - 1 !== $expectedLastRow) {
                throw new GarImportFailed('GAR streaming COPY did not produce the complete planned row range.');
            }

            $this->sendChunk($chunk, $profile);
            $this->endCopy($profile);
            $copyOpen = false;
            $this->assertCopySucceeded($profile);
            $processedRows = $this->commitSegment(
                $operationId,
                $fileKey,
                $segmentFirstRow,
                $segmentLastRow,
                $segmentRows,
                $segmentBytes,
                $profile,
            );
            ++$transactions;
        } catch (Throwable $error) {
            if ($copyOpen) {
                $this->abortCopy($error);
            }
            if ($transactionOpen && \pg_connection_status($this->connection) === PGSQL_CONNECTION_OK) {
                \pg_query($this->connection, 'ROLLBACK');
            }
            throw $error;
        }

        return new GarDirectCopyStreamResult(
            processedRows: $processedRows,
            rows: $totalRows,
            encodedBytes: $totalBytes,
            transactions: $transactions,
        );
    }

    private function beginCopy(string $copyTarget, ?GarFusedRangeProfile $profile): void
    {
        $startedAt = $profile instanceof GarFusedRangeProfile ? \hrtime(true) : 0;
        $this->command('BEGIN', 'Unable to begin GAR streaming COPY transaction.');
        if ($profile instanceof GarFusedRangeProfile) {
            $profile->beginNanoseconds += (int) (\hrtime(true) - $startedAt);
            $startedAt = \hrtime(true);
        }
        $result = \pg_query($this->connection, "COPY {$copyTarget} FROM STDIN");
        if (!$result instanceof Result || \pg_result_status($result) !== PGSQL_COPY_IN) {
            throw new GarImportFailed('PostgreSQL did not enter GAR streaming COPY mode.');
        }
        \pg_free_result($result);
        if ($profile instanceof GarFusedRangeProfile) {
            $profile->copyBeginNanoseconds += (int) (\hrtime(true) - $startedAt);
        }
    }

    private function sendChunk(string &$chunk, ?GarFusedRangeProfile $profile): void
    {
        if ($chunk === '') {
            return;
        }
        $startedAt = $profile instanceof GarFusedRangeProfile ? \hrtime(true) : 0;
        if (\pg_put_copy_data($this->connection, $chunk) !== 1) {
            throw new GarImportFailed('Unable to send GAR streaming COPY data.');
        }
        if ($profile instanceof GarFusedRangeProfile) {
            $profile->copySendNanoseconds += (int) (\hrtime(true) - $startedAt);
        }
        $chunk = '';
    }

    private function endCopy(?GarFusedRangeProfile $profile): void
    {
        $startedAt = $profile instanceof GarFusedRangeProfile ? \hrtime(true) : 0;
        if (\pg_put_copy_end($this->connection) !== 1) {
            throw new GarImportFailed('Unable to finish GAR streaming COPY data.');
        }
        if ($profile instanceof GarFusedRangeProfile) {
            $profile->copyEndNanoseconds += (int) (\hrtime(true) - $startedAt);
        }
    }

    private function assertCopySucceeded(?GarFusedRangeProfile $profile): void
    {
        $startedAt = $profile instanceof GarFusedRangeProfile ? \hrtime(true) : 0;
        $result = \pg_get_result($this->connection);
        if (!$result instanceof Result || \pg_result_status($result) !== PGSQL_COMMAND_OK) {
            throw new GarImportFailed('GAR streaming COPY failed.');
        }
        \pg_free_result($result);
        if ($profile instanceof GarFusedRangeProfile) {
            $profile->copyEndNanoseconds += (int) (\hrtime(true) - $startedAt);
        }
    }

    private function commitSegment(
        int $operationId,
        string $fileKey,
        int $firstRow,
        int $lastRow,
        int $rows,
        int $encodedBytes,
        ?GarFusedRangeProfile $profile,
    ): int {
        $startedAt = $profile instanceof GarFusedRangeProfile ? \hrtime(true) : 0;
        $processedRows = $this->ledger->record(
            operationId: $operationId,
            fileKey: $fileKey,
            firstRow: $firstRow,
            lastRow: $lastRow,
            rowCount: $rows,
            encodedBytes: $encodedBytes,
        );
        if ($profile instanceof GarFusedRangeProfile) {
            $profile->ledgerNanoseconds += (int) (\hrtime(true) - $startedAt);
            $startedAt = \hrtime(true);
        }
        $this->command('COMMIT', 'Unable to commit GAR streaming COPY transaction.');
        if ($profile instanceof GarFusedRangeProfile) {
            $profile->commitNanoseconds += (int) (\hrtime(true) - $startedAt);
        }

        return $processedRows;
    }

    private function abortCopy(Throwable $error): void
    {
        if (\pg_connection_status($this->connection) !== PGSQL_CONNECTION_OK) {
            return;
        }
        \pg_put_copy_end($this->connection, \substr($error->getMessage(), 0, 256));
        $result = \pg_get_result($this->connection);
        if ($result instanceof Result) {
            \pg_free_result($result);
        }
    }

    private function command(string $sql, string $failure): void
    {
        $result = \pg_query($this->connection, $sql);
        if (!$result instanceof Result || \pg_result_status($result) !== PGSQL_COMMAND_OK) {
            throw new GarImportFailed($failure);
        }
        \pg_free_result($result);
    }
}
