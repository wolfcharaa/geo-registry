<?php

declare(strict_types=1);

namespace App\Application\Worker;

use Amp\Cancellation;
use App\Application\Observability\CorrelationContext;
use App\Application\Observability\CorrelationIds;
use Fedor\MessageBus\Worker\Queue\QueueWorkerInterface;
use Fedor\MessageBus\Worker\Queue\ReceivedQueueMessage;

final readonly class CorrelatedQueueWorker implements QueueWorkerInterface
{
    public function __construct(
        private QueueWorkerInterface $worker,
        private CorrelationContext $correlationContext,
    ) {}

    public function handle(ReceivedQueueMessage $message, Cancellation $cancellation): void
    {
        $this->correlationContext->run(
            new CorrelationIds(
                messageId: $message->message->messageId,
                correlationId: $message->message->correlationId,
                jobId: $message->queueMessageId,
            ),
            function () use ($message, $cancellation): void {
                $this->worker->handle($message, $cancellation);
            },
        );
    }
}
