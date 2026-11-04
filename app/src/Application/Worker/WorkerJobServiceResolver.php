<?php

declare(strict_types=1);

namespace App\Application\Worker;

use App\Application\Observability\CorrelationContext;
use Fedor\MessageBus\Envelope\EnvelopeSerializerInterface;
use Fedor\MessageBus\Execution\BindingEnvelopeExecutorInterface;
use Fedor\MessageBus\Service\ServiceReference;
use Fedor\MessageBus\Service\ServiceResolutionFailed;
use Fedor\MessageBus\Service\ServiceResolverInterface;
use Fedor\MessageBus\Worker\Queue\MessageBusQueueWorker;
use Fedor\MessageBus\Worker\Queue\QueueWorkerInterface;

final class WorkerJobServiceResolver implements ServiceResolverInterface
{
    private ?QueueWorkerInterface $worker = null;

    public function __construct(
        private readonly ServiceResolverInterface $services,
        private readonly BindingEnvelopeExecutorInterface $executor,
        private readonly EnvelopeSerializerInterface $serializer,
        private readonly CorrelationContext $correlationContext,
    ) {}

    public function resolve(ServiceReference $reference): object
    {
        if ($reference->id !== QueueWorkerInterface::class) {
            return $this->services->resolve($reference);
        }

        $worker = $this->worker ??= new CorrelatedQueueWorker(
            new MessageBusQueueWorker($this->executor, $this->serializer, $this),
            $this->correlationContext,
        );
        if (!$worker instanceof $reference->type) {
            throw ServiceResolutionFailed::typeMismatch($reference, $worker);
        }

        return $worker;
    }
}
