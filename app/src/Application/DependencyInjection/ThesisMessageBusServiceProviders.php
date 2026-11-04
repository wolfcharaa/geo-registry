<?php

declare(strict_types=1);

namespace App\Application\DependencyInjection;

use App\Application\MessageBus\CorrelationContextInterceptor;
use App\Application\MessageBus\MessageBusServiceMap;
use App\Application\MessageBus\MessageBusTelemetryInterceptor;
use App\Application\Observability\CorrelationContext;
use Psr\Log\LoggerInterface;
use Thesis\Dic;
use Thesis\Dic\Configuration\ProviderConfig;
use Thesis\Dic\Ref;
use UnexpectedValueException;

final readonly class ThesisMessageBusServiceProviders
{
    /**
     * @param Ref<CorrelationContext> $correlationContext
     * @param Ref<LoggerInterface> $logger
     * @param array<string, Ref<object>> $overrides
     */
    public function __construct(
        private MessageBusServiceMap $services,
        private Ref $correlationContext,
        private Ref $logger,
        private array $overrides = [],
    ) {
        $this->services->validateOverrides($this->overrides);
    }

    /** @return array<string, ProviderConfig<object>> */
    public function configure(Dic $dic): array
    {
        $providers = [];
        foreach ($this->services->types as $id => $serviceType) {
            if (!is_string($id)) {
                throw new UnexpectedValueException('MessageBus service IDs must be non-numeric strings.');
            }
            $service = $this->overrides[$id] ?? match ($serviceType) {
                CorrelationContextInterceptor::class => $dic
                    ->object(CorrelationContextInterceptor::class)
                    ->arg('correlationContext', $this->correlationContext),
                MessageBusTelemetryInterceptor::class => $dic
                    ->object(MessageBusTelemetryInterceptor::class)
                    ->arg('logger', $this->logger),
                default => $dic->object($serviceType)->canBeScoped(),
            };
            $providers[$id] = $dic->provider($service);
        }

        return $providers;
    }
}
