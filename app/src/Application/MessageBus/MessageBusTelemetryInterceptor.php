<?php

declare(strict_types=1);

namespace App\Application\MessageBus;

use Fedor\MessageBus\Context\MessageContextInterface;
use Fedor\MessageBus\Interceptor\InterceptorInterface;
use Fedor\MessageBus\Interceptor\PipelineInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class MessageBusTelemetryInterceptor implements InterceptorInterface
{
    public function __construct(private LoggerInterface $logger) {}

    public function __invoke(MessageContextInterface $context, PipelineInterface $pipeline): mixed
    {
        $envelope = $context->envelope();
        $startedAt = hrtime(true);
        $metadata = [
            'message_class' => $envelope->message::class,
            'binding_id' => $envelope->bindingId,
        ];
        $this->logger->info('Message execution started', [
            'event' => 'message.execution.started',
            ...$metadata,
        ]);

        try {
            $result = $pipeline->continue();
        } catch (Throwable $error) {
            $this->logger->error('Message execution failed', [
                'event' => 'message.execution.failed',
                ...$metadata,
                'duration_ms' => $this->durationMilliseconds($startedAt),
                'exception' => $error::class,
            ]);

            throw $error;
        }

        $this->logger->info('Message execution completed', [
            'event' => 'message.execution.completed',
            ...$metadata,
            'duration_ms' => $this->durationMilliseconds($startedAt),
        ]);

        return $result;
    }

    private function durationMilliseconds(int|float $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 3);
    }
}
