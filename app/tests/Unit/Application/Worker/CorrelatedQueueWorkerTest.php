<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Worker;

use Amp\Cancellation;
use Amp\NullCancellation;
use App\Application\Observability\CorrelationContext;
use App\Application\Observability\CorrelationIds;
use App\Application\Worker\CorrelatedQueueWorker;
use DateTimeImmutable;
use Fedor\MessageBus\Envelope\SerializedEnvelope;
use Fedor\MessageBus\Queue\QueueMessage;
use Fedor\MessageBus\Worker\Queue\QueueWorkerInterface;
use Fedor\MessageBus\Worker\Queue\ReceivedQueueMessage;
use Testo\Assert;
use Testo\Test;

#[Test]
final class CorrelatedQueueWorkerTest
{
    public function testItSeedsAndClearsTheQueueJobCorrelationContext(): void
    {
        $context = new CorrelationContext();
        $worker = new RecordingQueueWorker($context);
        $message = new ReceivedQueueMessage(
            'job-1',
            new QueueMessage(
                transport: 'postgres',
                queue: 'messages',
                envelope: new SerializedEnvelope('test.message', '{}'),
                messageId: 'message-1',
                correlationId: 'correlation-1',
                flow: 'async',
                bindingId: 'test.binding',
                availableAt: new DateTimeImmutable(),
            ),
            attempts: 1,
            leaseOwner: 'worker-1',
        );

        (new CorrelatedQueueWorker($worker, $context))->handle($message, new NullCancellation());

        Assert::notNull($worker->observed);
        Assert::same($worker->observed->toArray(), [
            'message_id' => 'message-1',
            'correlation_id' => 'correlation-1',
            'job_id' => 'job-1',
        ]);
        Assert::same($context->current(), null);
    }
}

final class RecordingQueueWorker implements QueueWorkerInterface
{
    public ?CorrelationIds $observed = null;

    public function __construct(private readonly CorrelationContext $context) {}

    public function handle(ReceivedQueueMessage $message, Cancellation $cancellation): void
    {
        $this->observed = $this->context->current();
    }
}
