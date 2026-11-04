<?php

declare(strict_types=1);

namespace App\Application\MessageBus;

use App\Application\Observability\CorrelationContext;
use App\Application\Observability\CorrelationIds;
use Fedor\MessageBus\Context\MessageContextInterface;
use Fedor\MessageBus\Interceptor\InterceptorInterface;
use Fedor\MessageBus\Interceptor\PipelineInterface;

final readonly class CorrelationContextInterceptor implements InterceptorInterface
{
    public function __construct(private CorrelationContext $correlationContext) {}

    public function __invoke(MessageContextInterface $context, PipelineInterface $pipeline): mixed
    {
        $current = $this->correlationContext->current();
        $envelope = $context->envelope();

        return $this->correlationContext->run(
            new CorrelationIds(
                requestId: $current?->requestId,
                messageId: $envelope->messageId,
                correlationId: $envelope->correlationId,
                operationId: $current?->operationId,
                jobId: $current?->jobId,
            ),
            static fn(): mixed => $pipeline->continue(),
        );
    }
}
