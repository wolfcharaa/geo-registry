<?php

declare(strict_types=1);

namespace App\Benchmark\Support;

use Amp\NullCancellation;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarParsedXmlRange;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlByteRange;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlRangeParser;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlSourceFile;
use Generator;
use HashContext;
use PgSql\Connection;
use PgSql\Result;
use RuntimeException;
use Throwable;

/** Benchmark-only causal profile of array and process-local streaming COPY. */
final readonly class GarRangeTransportProfiler
{
    private const STREAM_PREFETCH_BYTES = 1024 * 1024;

    public function __construct(
        private Connection $connection,
        private int $maxEncodedBytes,
        private int $transportBytes = 1024 * 1024,
    ) {
        if ($this->transportBytes < 1 || $this->maxEncodedBytes < $this->transportBytes) {
            throw new RuntimeException('GAR range transport profile boundaries are invalid.');
        }
    }

    public function prepare(GarXmlSourceFile $file): void
    {
        $table = $file->descriptor->table;
        self::command(
            $this->connection,
            "CREATE TEMP TABLE {$table} (LIKE public.{$table} INCLUDING DEFAULTS INCLUDING GENERATED "
                . 'INCLUDING IDENTITY INCLUDING CONSTRAINTS) ON COMMIT PRESERVE ROWS',
            'Unable to create GAR range profile target.',
        );
        self::command($this->connection, <<<'SQL'
CREATE TEMP TABLE gar_range_profile_ledger (
    sequence integer PRIMARY KEY,
    first_row bigint NOT NULL,
    last_row bigint NOT NULL,
    row_count bigint NOT NULL,
    encoded_bytes bigint NOT NULL
) ON COMMIT PRESERVE ROWS
SQL, 'Unable to create GAR range profile ledger.');
        self::command(
            $this->connection,
            'SET search_path = pg_temp, public',
            'Unable to select the GAR range profile schema.',
        );
    }

    /**
     * @param list<GarXmlByteRange> $ranges
     * @return array<string, int|float|string>
     */
    public function profile(string $mode, GarXmlSourceFile $file, array $ranges): array
    {
        if (!\in_array($mode, ['array', 'stream'], true) || $ranges === []) {
            throw new RuntimeException('GAR range transport profile received an invalid mode or empty sample.');
        }

        self::command(
            $this->connection,
            "TRUNCATE {$file->descriptor->table}, gar_range_profile_ledger",
            'Unable to reset GAR range profile tables.',
        );
        \gc_collect_cycles();
        \memory_reset_peak_usage();
        $memoryBefore = \memory_get_usage(true);

        $metrics = [
            'decode_before_nanoseconds' => 0,
            'decode_while_permit_nanoseconds' => 0,
            'begin_nanoseconds' => 0,
            'copy_begin_nanoseconds' => 0,
            'copy_send_nanoseconds' => 0,
            'copy_end_nanoseconds' => 0,
            'ledger_nanoseconds' => 0,
            'commit_nanoseconds' => 0,
            'rows' => 0,
            'encoded_bytes' => 0,
            'chunks' => 0,
            'transactions' => 0,
            'admitted_peak_bytes' => 0,
        ];
        $hash = \hash_init('sha256');
        $parser = new GarXmlRangeParser($this->maxEncodedBytes);
        $cancellation = new NullCancellation();
        $startedAt = \hrtime(true);

        foreach ($ranges as $sequence => $range) {
            if ($mode === 'array') {
                $decodeStartedAt = \hrtime(true);
                $parsed = $parser->parse($file, $range, $cancellation);
                $metrics['decode_before_nanoseconds'] += \hrtime(true) - $decodeStartedAt;
                $metrics['admitted_peak_bytes'] = \max(
                    $metrics['admitted_peak_bytes'],
                    $parsed->encodedBytes,
                );
                $rows = self::arrayRows($parsed);
            } else {
                $rows = $this->streamRows($parser, $file, $range, $metrics);
            }

            $this->copyRange(
                $file->descriptor->directCopyTarget(),
                $range,
                $sequence + 1,
                $rows,
                $hash,
                $metrics,
            );
        }

        $wallNanoseconds = \hrtime(true) - $startedAt;
        $storedRows = $this->storedRows($file->descriptor->table);
        if ($storedRows !== $metrics['rows']) {
            throw new RuntimeException('GAR range transport profile stored row count is not exact.');
        }

        return [
            'mode' => $mode,
            'rows' => $metrics['rows'],
            'stored_rows' => $storedRows,
            'encoded_bytes' => $metrics['encoded_bytes'],
            'sha256' => \hash_final($hash),
            'ranges' => \count($ranges),
            'transactions' => $metrics['transactions'],
            'chunks' => $metrics['chunks'],
            'admitted_peak_bytes' => $metrics['admitted_peak_bytes'],
            'permit_wait_seconds' => 0.0,
            'decode_before_seconds' => $metrics['decode_before_nanoseconds'] / 1_000_000_000,
            'decode_while_permit_seconds' => $metrics['decode_while_permit_nanoseconds'] / 1_000_000_000,
            'begin_seconds' => $metrics['begin_nanoseconds'] / 1_000_000_000,
            'copy_begin_seconds' => $metrics['copy_begin_nanoseconds'] / 1_000_000_000,
            'copy_send_seconds' => $metrics['copy_send_nanoseconds'] / 1_000_000_000,
            'copy_end_seconds' => $metrics['copy_end_nanoseconds'] / 1_000_000_000,
            'ledger_seconds' => $metrics['ledger_nanoseconds'] / 1_000_000_000,
            'commit_seconds' => $metrics['commit_nanoseconds'] / 1_000_000_000,
            'wall_seconds' => $wallNanoseconds / 1_000_000_000,
            'memory_before_bytes' => $memoryBefore,
            'peak_memory_bytes' => \memory_get_peak_usage(true),
            'peak_memory_increment_bytes' => \memory_get_peak_usage(true) - $memoryBefore,
        ];
    }

    /** @return Generator<int, string> */
    private static function arrayRows(GarParsedXmlRange $parsed): Generator
    {
        $rowNumber = $parsed->firstRow;
        foreach ($parsed->rows as $encoded) {
            yield $rowNumber => $encoded;
            ++$rowNumber;
        }
    }

    /**
     * @param array<string, int> $metrics
     * @return Generator<int, string>
     */
    private function streamRows(
        GarXmlRangeParser $parser,
        GarXmlSourceFile $file,
        GarXmlByteRange $range,
        array &$metrics,
    ): Generator {
        $remaining = $parser->encodedRows($file, $range, new NullCancellation());
        $prefetched = [];
        $prefetchedBytes = 0;
        $advanceBeforeRemaining = false;

        $decodeStartedAt = \hrtime(true);
        $remaining->rewind();
        $metrics['decode_before_nanoseconds'] += \hrtime(true) - $decodeStartedAt;
        while ($remaining->valid()) {
            $rowNumber = $remaining->key();
            $encoded = $remaining->current();
            $prefetched[$rowNumber] = $encoded;
            $prefetchedBytes += \strlen($encoded);
            if ($prefetchedBytes >= self::STREAM_PREFETCH_BYTES) {
                $advanceBeforeRemaining = true;
                break;
            }
            $decodeStartedAt = \hrtime(true);
            $remaining->next();
            $metrics['decode_before_nanoseconds'] += \hrtime(true) - $decodeStartedAt;
        }
        if ($prefetched === []) {
            throw new RuntimeException('GAR streaming range profile produced no prefetched rows.');
        }
        $metrics['admitted_peak_bytes'] = \max($metrics['admitted_peak_bytes'], $prefetchedBytes);

        return (static function () use (
            $prefetched,
            $remaining,
            $advanceBeforeRemaining,
            &$metrics,
        ): Generator {
            foreach ($prefetched as $rowNumber => $encoded) {
                yield $rowNumber => $encoded;
            }
            if ($advanceBeforeRemaining) {
                $decodeStartedAt = \hrtime(true);
                $remaining->next();
                $metrics['decode_while_permit_nanoseconds'] += \hrtime(true) - $decodeStartedAt;
            }
            while ($remaining->valid()) {
                $rowNumber = $remaining->key();
                $encoded = $remaining->current();
                yield $rowNumber => $encoded;
                $decodeStartedAt = \hrtime(true);
                $remaining->next();
                $metrics['decode_while_permit_nanoseconds'] += \hrtime(true) - $decodeStartedAt;
            }
        })();
    }

    /**
     * @param iterable<int, string> $rows
     * @param array<string, int> $metrics
     */
    private function copyRange(
        string $copyTarget,
        GarXmlByteRange $range,
        int $sequence,
        iterable $rows,
        HashContext $hash,
        array &$metrics,
    ): void {
        $transactionOpen = false;
        $copyOpen = false;

        try {
            $startedAt = \hrtime(true);
            self::command($this->connection, 'BEGIN', 'Unable to begin GAR range profile transaction.');
            $metrics['begin_nanoseconds'] += \hrtime(true) - $startedAt;
            $transactionOpen = true;

            $startedAt = \hrtime(true);
            $copyIn = \pg_query($this->connection, "COPY {$copyTarget} FROM STDIN");
            if (!$copyIn instanceof Result || \pg_result_status($copyIn) !== PGSQL_COPY_IN) {
                throw new RuntimeException('PostgreSQL did not enter GAR range profile COPY mode.');
            }
            \pg_free_result($copyIn);
            $metrics['copy_begin_nanoseconds'] += \hrtime(true) - $startedAt;
            $copyOpen = true;

            $chunk = '';
            $rangeRows = 0;
            $rangeBytes = 0;
            $expectedRow = $range->firstRow;
            foreach ($rows as $rowNumber => $encoded) {
                if ($rowNumber !== $expectedRow || $encoded === '') {
                    throw new RuntimeException('GAR range profile received non-contiguous or empty data.');
                }
                $encodedBytes = \strlen($encoded);
                if ($chunk !== '' && \strlen($chunk) + $encodedBytes > $this->transportBytes) {
                    $this->sendChunk($chunk, $metrics);
                }
                $chunk .= $encoded;
                \hash_update($hash, $encoded);
                ++$rangeRows;
                $rangeBytes += $encodedBytes;
                ++$expectedRow;
            }
            if ($expectedRow - 1 !== $range->lastRow) {
                throw new RuntimeException('GAR range profile did not consume the complete planned range.');
            }
            $this->sendChunk($chunk, $metrics);

            $startedAt = \hrtime(true);
            if (\pg_put_copy_end($this->connection) !== 1) {
                throw new RuntimeException('Unable to finish GAR range profile COPY data.');
            }
            $completed = \pg_get_result($this->connection);
            if (!$completed instanceof Result || \pg_result_status($completed) !== PGSQL_COMMAND_OK) {
                throw new RuntimeException('GAR range profile COPY failed.');
            }
            \pg_free_result($completed);
            $metrics['copy_end_nanoseconds'] += \hrtime(true) - $startedAt;
            $copyOpen = false;

            $startedAt = \hrtime(true);
            $ledger = \pg_query_params(
                $this->connection,
                'INSERT INTO gar_range_profile_ledger '
                    . '(sequence, first_row, last_row, row_count, encoded_bytes) VALUES ($1, $2, $3, $4, $5)',
                [
                    (string) $sequence,
                    (string) $range->firstRow,
                    (string) $range->lastRow,
                    (string) $rangeRows,
                    (string) $rangeBytes,
                ],
            );
            if (!$ledger instanceof Result || \pg_result_status($ledger) !== PGSQL_COMMAND_OK) {
                throw new RuntimeException('Unable to record the GAR range profile ledger.');
            }
            \pg_free_result($ledger);
            $metrics['ledger_nanoseconds'] += \hrtime(true) - $startedAt;

            $startedAt = \hrtime(true);
            self::command($this->connection, 'COMMIT', 'Unable to commit GAR range profile transaction.');
            $metrics['commit_nanoseconds'] += \hrtime(true) - $startedAt;
            $transactionOpen = false;

            $metrics['rows'] += $rangeRows;
            $metrics['encoded_bytes'] += $rangeBytes;
            ++$metrics['transactions'];
        } catch (Throwable $error) {
            if ($copyOpen && \pg_connection_status($this->connection) === PGSQL_CONNECTION_OK) {
                \pg_put_copy_end($this->connection, \substr($error->getMessage(), 0, 256));
                $result = \pg_get_result($this->connection);
                if ($result instanceof Result) {
                    \pg_free_result($result);
                }
            }
            if ($transactionOpen && \pg_connection_status($this->connection) === PGSQL_CONNECTION_OK) {
                \pg_query($this->connection, 'ROLLBACK');
            }

            throw $error;
        }
    }

    /** @param array<string, int> $metrics */
    private function sendChunk(string &$chunk, array &$metrics): void
    {
        if ($chunk === '') {
            return;
        }

        $startedAt = \hrtime(true);
        if (\pg_put_copy_data($this->connection, $chunk) !== 1) {
            throw new RuntimeException('Unable to send GAR range profile COPY data.');
        }
        $metrics['copy_send_nanoseconds'] += \hrtime(true) - $startedAt;
        ++$metrics['chunks'];
        $chunk = '';
    }

    private function storedRows(string $table): int
    {
        $result = \pg_query($this->connection, "SELECT count(*)::bigint AS rows FROM {$table}");
        if (!$result instanceof Result) {
            throw new RuntimeException('Unable to validate GAR range profile rows.');
        }
        $row = \pg_fetch_assoc($result);
        \pg_free_result($result);
        if (!\is_array($row) || !isset($row['rows'])) {
            throw new RuntimeException('PostgreSQL returned invalid GAR range profile validation data.');
        }

        return (int) $row['rows'];
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
