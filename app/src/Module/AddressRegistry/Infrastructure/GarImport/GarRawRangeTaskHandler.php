<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use Amp\Cancellation;
use Fedor\WorkOrchestrator\Contract\ManagedTask;
use Fedor\WorkOrchestrator\Contract\ManagedTaskHandler;
use PgSql\Connection;
use UnexpectedValueException;

/** Parses and copies one range entirely inside its reusable managed child process. */
final readonly class GarRawRangeTaskHandler implements ManagedTaskHandler
{
    public function __construct(private Connection $connection) {}

    public function handle(ManagedTask $task, Cancellation $cancellation): GarDirectCopyBatchResult
    {
        if (!$task instanceof GarRawRangeTask) {
            throw new UnexpectedValueException(\sprintf(
                'GAR raw range handler requires %s, %s given.',
                GarRawRangeTask::class,
                $task::class,
            ));
        }

        $cancellation->throwIfRequested();
        $profile = \getenv('GAR_FUSED_RANGE_PROFILE') === '1' ? new GarFusedRangeProfile() : null;
        $taskStartedAt = $profile instanceof GarFusedRangeProfile ? \hrtime(true) : 0;
        $writerStartedAt = $profile instanceof GarFusedRangeProfile ? \hrtime(true) : 0;
        $rows = new GarXmlRangeParser($task->maxEncodedBytes)
            ->encodedRows($task->file, $task->range, $cancellation);
        $result = new GarDirectCopyStreamWriter(
            connection: $this->connection,
            transactionBytes: $task->maxEncodedBytes,
        )->write(
            operationId: $task->operationId,
            fileKey: $task->file->key,
            copyTarget: $task->file->descriptor->directCopyTarget(),
            rows: $rows,
            expectedFirstRow: $task->range->firstRow,
            expectedLastRow: $task->range->lastRow,
            profile: $profile,
        );
        if ($profile instanceof GarFusedRangeProfile) {
            $profile->writerNanoseconds = (int) (\hrtime(true) - $writerStartedAt);
            $profile->taskNanoseconds = (int) (\hrtime(true) - $taskStartedAt);
        }

        return new GarDirectCopyBatchResult(
            firstRow: $task->range->firstRow,
            lastRow: $task->range->lastRow,
            processedRows: $result->processedRows,
            encodedBytes: $result->encodedBytes,
            backendPid: \pg_get_pid($this->connection),
            profile: $profile,
        );
    }
}
