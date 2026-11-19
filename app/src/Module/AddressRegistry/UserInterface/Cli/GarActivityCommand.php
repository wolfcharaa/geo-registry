<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\UserInterface\Cli;

use App\Module\AddressRegistry\Capability\Activity\GarActivityReader;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'gar:activity',
    description: 'Показывает активные SQL и durable GAR/worker operations.',
)]
final class GarActivityCommand extends Command
{
    public function __construct(private readonly GarActivityReader $reader)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'limit',
            null,
            InputOption::VALUE_REQUIRED,
            'Максимум SQL, worker и queue rows в каждой секции; 1..100.',
            '20',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $limit = self::limit($input);
        } catch (InvalidArgumentException $error) {
            $output->writeln('<error>' . $error->getMessage() . '</error>');

            return self::INVALID;
        }

        try {
            $snapshot = $this->reader->read($limit);
        } catch (RuntimeException $error) {
            $output->writeln('<error>' . $error->getMessage() . '</error>');

            return self::FAILURE;
        }

        $output->writeln('postgres_activity count=' . \count($snapshot->statements));
        foreach ($snapshot->statements as $statement) {
            $wait = \implode('/', \array_filter([$statement->waitEventType, $statement->waitEvent], 'is_string'));
            $output->writeln(\sprintf(
                'sql pid=%d application="%s" state=%s age=%s wait=%s query="%s"',
                $statement->pid,
                self::quoted($statement->application),
                $statement->state,
                $statement->age,
                $wait === '' ? 'none' : $wait,
                self::quoted($statement->query),
            ));
        }

        $output->writeln('operations count=' . \count($snapshot->operations));
        foreach ($snapshot->operations as $operation) {
            $output->writeln(\sprintf(
                'operation type=%s id=%d status=%s mode=%s phase=%s write=%s attempt=%s batch_size=%s '
                    . 'steps=%d/%d rows=%d/%d updated_at="%s" '
                    . 'progress_age=%s error="%s"',
                $operation->type,
                $operation->operationId,
                $operation->status,
                $operation->mode ?? 'none',
                $operation->phase ?? 'none',
                $operation->writeMode ?? 'none',
                $operation->attempt === null ? 'none' : (string) $operation->attempt,
                $operation->batchSize === null ? 'none' : (string) $operation->batchSize,
                $operation->stepsCompleted,
                $operation->stepsTotal,
                $operation->rowsRead,
                $operation->rowsWritten,
                self::quoted($operation->updatedAt),
                $operation->progressAge,
                self::quoted($operation->error ?? 'none'),
            ));
            if ($operation->pipelineState !== null) {
                $output->writeln(\sprintf(
                    'operation_pipeline id=%d state=%s target=%s writers=%s batches=%d bytes=%d '
                        . 'peak_batches=%d peak_bytes=%d pending_ranges=%d pending_rows=%d progress_age=%s',
                    $operation->operationId,
                    $operation->pipelineState,
                    $operation->pipelineFile ?? 'off',
                    $operation->pipelineWriters === null ? 'off' : (string) $operation->pipelineWriters,
                    $operation->pipelineBatches,
                    $operation->pipelineBytes,
                    $operation->pipelinePeakInFlightBatches,
                    $operation->pipelinePeakInFlightBytes,
                    $operation->pipelinePendingRanges,
                    $operation->pipelinePendingRows,
                    $operation->pipelineProgressAge ?? 'none',
                ));
            }
            if ($operation->type === 'gar_import') {
                $output->writeln(\sprintf(
                    'operation_foreign_keys id=%d mode=%s completed=%d/%d',
                    $operation->operationId,
                    $operation->deferForeignKeys ? 'deferred' : 'active',
                    $operation->foreignKeysCompleted,
                    $operation->foreignKeysTotal,
                ));
            }
        }

        $output->writeln('workers count=' . \count($snapshot->workers));
        foreach ($snapshot->workers as $worker) {
            $output->writeln(\sprintf(
                'worker instance=%s name="%s" state=%s activity=%s transport=%s queue=%s heartbeat_at="%s" heartbeat_age=%s',
                $worker->instanceId,
                self::quoted($worker->name),
                $worker->state,
                $worker->activity,
                $worker->transport,
                $worker->queue,
                self::quoted($worker->heartbeatAt),
                $worker->heartbeatAge,
            ));
        }

        $output->writeln('gar_queue_activity count=' . \count($snapshot->queues));
        foreach ($snapshot->queues as $queue) {
            $output->writeln(\sprintf(
                'queue flow=%s binding=%s status=%s jobs=%d oldest_at="%s"',
                $queue->flow,
                $queue->bindingId,
                $queue->status,
                $queue->jobs,
                self::quoted($queue->oldestAt),
            ));
        }

        return self::SUCCESS;
    }

    private static function limit(InputInterface $input): int
    {
        $limit = $input->getOption('limit');
        if (!\is_string($limit) || !\ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 100) {
            throw new InvalidArgumentException('Option --limit must be an integer from 1 to 100.');
        }

        return (int) $limit;
    }

    private static function quoted(string $value): string
    {
        return \str_replace(
            ["\\", '"', "\r", "\n"],
            ["\\\\", '\\"', '\\r', '\\n'],
            $value,
        );
    }
}
