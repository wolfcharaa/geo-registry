<?php

declare(strict_types=1);

namespace App\Application\MessageBus;

use Fedor\MessageBus\Context\DefaultMessageContextFactory;
use Fedor\MessageBus\Context\MessageContextFactoryInterface;
use Fedor\MessageBus\Execution\HandlerExecutionStrategyInterface;
use Fedor\MessageBus\Execution\QueueExecutionStrategy;
use Fedor\MessageBus\Execution\SequentialExecutionStrategy;
use Fedor\MessageBus\Interceptor\InterceptorInterface;
use Fedor\MessageBus\Registry\CompiledMessageRegistry;
use Fedor\MessageBus\Service\ServiceReference;
use InvalidArgumentException;

final readonly class MessageBusServiceMap
{
    /** @var array<string, class-string<object>> */
    public array $types;

    private function __construct(MessageBusRegistrySource $source, CompiledMessageRegistry $registry)
    {
        $this->types = array_replace([
            DefaultMessageContextFactory::class => DefaultMessageContextFactory::class,
            QueueExecutionStrategy::class => QueueExecutionStrategy::class,
            SequentialExecutionStrategy::class => SequentialExecutionStrategy::class,
            CorrelationContextInterceptor::class => CorrelationContextInterceptor::class,
            MessageBusTelemetryInterceptor::class => MessageBusTelemetryInterceptor::class,
        ], $source->services);
        $this->validateRequirements($registry);
    }

    public static function from(MessageBusRegistrySource $source, CompiledMessageRegistry $registry): self
    {
        return new self($source, $registry);
    }

    /** @param array<string, mixed> $overrides */
    public function validateOverrides(array $overrides): void
    {
        foreach ($overrides as $id => $_service) {
            if (!isset($this->types[$id])) {
                throw new InvalidArgumentException(sprintf(
                    'MessageBus service override `%s` is not declared in the application registry.',
                    $id,
                ));
            }
        }
    }

    private function validateRequirements(CompiledMessageRegistry $registry): void
    {
        foreach ($registry->compiled()->flows->all() as $flow) {
            if ($flow->contextFactory !== null) {
                $this->validateServiceType(
                    new ServiceReference($flow->contextFactory, MessageContextFactoryInterface::class),
                );
            }
            $this->validateServiceType(
                new ServiceReference($flow->strategy, HandlerExecutionStrategyInterface::class),
            );
            foreach ($flow->middleware as $interceptor) {
                $this->validateServiceType(
                    new ServiceReference($interceptor, InterceptorInterface::class),
                );
            }
        }

        foreach ($registry->compiled()->bindings() as $binding) {
            $this->validateServiceType($binding->handler);
            foreach ($binding->interceptors as $interceptor) {
                $this->validateServiceType($interceptor);
            }
        }
    }

    /** @param ServiceReference<object> $reference */
    private function validateServiceType(ServiceReference $reference): void
    {
        $serviceType = $this->types[$reference->id] ?? throw new InvalidArgumentException(sprintf(
            'MessageBus service `%s` with type `%s` is not declared in the application registry.',
            $reference->id,
            $reference->type,
        ));
        if (!is_a($serviceType, $reference->type, true)) {
            throw new InvalidArgumentException(sprintf(
                'MessageBus service `%s` must implement `%s`; `%s` declared.',
                $reference->id,
                $reference->type,
                $serviceType,
            ));
        }
    }
}
