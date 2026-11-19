<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use Generator;
use PgSql\Connection;

/** @implements Task<GarDirectCopyBatchResult, bool, GarDirectRangeReady> */
final class GarParseAndCopyRangeTask implements Task
{
    private const STREAM_PREFETCH_BYTES = 1024 * 1024;

    private static ?Connection $connection = null;

    private static ?string $connectionDsn = null;

    public function __construct(
        private readonly string $databaseDsn,
        private readonly int $operationId,
        private readonly GarXmlSourceFile $file,
        private readonly GarXmlByteRange $range,
        private readonly int $maxEncodedBytes = 8 * 1024 * 1024,
    ) {}

    /** @param Channel<bool, GarDirectRangeReady> $channel */
    public function run(Channel $channel, Cancellation $cancellation): GarDirectCopyBatchResult
    {
        $profile = \getenv('GAR_FUSED_RANGE_PROFILE') === '1' ? new GarFusedRangeProfile() : null;
        $taskStartedAt = $profile instanceof GarFusedRangeProfile ? \hrtime(true) : 0;
        $prefetchStartedAt = $profile instanceof GarFusedRangeProfile ? \hrtime(true) : 0;
        $remaining = new GarXmlRangeParser($this->maxEncodedBytes)
            ->encodedRows($this->file, $this->range, $cancellation);
        $remaining->rewind();
        $prefetched = [];
        $prefetchedBytes = 0;
        $advanceBeforeRemaining = false;
        while ($remaining->valid()) {
            $rowNumber = $remaining->key();
            $encoded = $remaining->current();
            $prefetched[$rowNumber] = $encoded;
            $prefetchedBytes += \strlen($encoded);
            if ($prefetchedBytes >= self::STREAM_PREFETCH_BYTES) {
                $advanceBeforeRemaining = true;
                break;
            }
            $remaining->next();
        }
        if ($prefetched === []) {
            throw new GarImportFailed('GAR streaming parser returned no rows.');
        }
        if ($profile instanceof GarFusedRangeProfile) {
            $profile->prefetchNanoseconds = (int) (\hrtime(true) - $prefetchStartedAt);
        }

        $permitStartedAt = $profile instanceof GarFusedRangeProfile ? \hrtime(true) : 0;
        $channel->send(new GarDirectRangeReady(
            firstRow: $this->range->firstRow,
            lastRow: $this->range->lastRow,
            encodedBytes: $prefetchedBytes,
        ));
        if ($channel->receive($cancellation) !== true) {
            throw new GarImportFailed('GAR streaming range worker received an invalid COPY permit.');
        }
        if ($profile instanceof GarFusedRangeProfile) {
            $profile->permitWaitNanoseconds = (int) (\hrtime(true) - $permitStartedAt);
        }

        $cancellation->throwIfRequested();
        $connectionStartedAt = $profile instanceof GarFusedRangeProfile ? \hrtime(true) : 0;
        $connection = $this->connection();
        if ($profile instanceof GarFusedRangeProfile) {
            $profile->connectionNanoseconds = (int) (\hrtime(true) - $connectionStartedAt);
        }
        $writerStartedAt = $profile instanceof GarFusedRangeProfile ? \hrtime(true) : 0;
        $result = new GarDirectCopyStreamWriter(
            connection: $connection,
            transactionBytes: $this->maxEncodedBytes,
        )->write(
            operationId: $this->operationId,
            fileKey: $this->file->key,
            copyTarget: $this->file->descriptor->directCopyTarget(),
            rows: self::streamRows($prefetched, $remaining, $advanceBeforeRemaining, $profile),
            expectedFirstRow: $this->range->firstRow,
            expectedLastRow: $this->range->lastRow,
            profile: $profile,
        );
        if ($profile instanceof GarFusedRangeProfile) {
            $profile->writerNanoseconds = (int) (\hrtime(true) - $writerStartedAt);
            $profile->taskNanoseconds = (int) (\hrtime(true) - $taskStartedAt);
        }

        return new GarDirectCopyBatchResult(
            firstRow: $this->range->firstRow,
            lastRow: $this->range->lastRow,
            processedRows: $result->processedRows,
            encodedBytes: $result->encodedBytes,
            backendPid: \pg_get_pid($connection),
            profile: $profile,
        );
    }

    /**
     * @param non-empty-array<int, string> $prefetched
     * @param Generator<int, string, mixed, void> $remaining
     * @return Generator<int, string>
     */
    private static function streamRows(
        array $prefetched,
        Generator $remaining,
        bool $advanceBeforeRemaining,
        ?GarFusedRangeProfile $profile,
    ): Generator {
        foreach ($prefetched as $rowNumber => $encoded) {
            yield $rowNumber => $encoded;
        }
        if (!$profile instanceof GarFusedRangeProfile) {
            if ($advanceBeforeRemaining) {
                $remaining->next();
            }
            while ($remaining->valid()) {
                $rowNumber = $remaining->key();
                $encoded = $remaining->current();
                yield $rowNumber => $encoded;
                $remaining->next();
            }
            return;
        }

        if ($advanceBeforeRemaining) {
            $startedAt = \hrtime(true);
            $remaining->next();
            $profile->permitDecodeNanoseconds += (int) (\hrtime(true) - $startedAt);
        }
        while ($remaining->valid()) {
            $rowNumber = $remaining->key();
            $encoded = $remaining->current();
            yield $rowNumber => $encoded;
            $startedAt = \hrtime(true);
            $remaining->next();
            $profile->permitDecodeNanoseconds += (int) (\hrtime(true) - $startedAt);
        }
    }

    private function connection(): Connection
    {
        if (
            self::$connection instanceof Connection
            && self::$connectionDsn === $this->databaseDsn
            && \pg_connection_status(self::$connection) === PGSQL_CONNECTION_OK
        ) {
            return self::$connection;
        }
        if (self::$connection instanceof Connection) {
            \pg_close(self::$connection);
        }
        self::$connection = new GarImportConnectionFactory($this->databaseDsn)->connect();
        self::$connectionDsn = $this->databaseDsn;

        return self::$connection;
    }
}
