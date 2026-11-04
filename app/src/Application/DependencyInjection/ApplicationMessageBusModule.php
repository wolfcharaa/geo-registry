<?php

declare(strict_types=1);

namespace App\Application\DependencyInjection;

use App\Application\MessageBus\ApplicationRegistryCompiler;
use App\Application\MessageBus\CorrelationContextInterceptor;
use App\Application\MessageBus\MessageBusRegistrySource;
use App\Application\Observability\CorrelationContext;
use Fedor\MessageBus\Context\DefaultMessageContextFactory;
use Fedor\MessageBus\Context\MessageContextFactoryInterface;
use Fedor\MessageBus\Execution\HandlerExecutionStrategyInterface;
use Fedor\MessageBus\Execution\SequentialExecutionStrategy;
use Fedor\MessageBus\Integration\Thesis\ThesisServiceResolver;
use Fedor\MessageBus\Integration\Thesis\ThesisServiceScopeFactory;
use Fedor\MessageBus\Interceptor\InterceptorInterface;
use Fedor\MessageBus\MessageBus;
use Fedor\MessageBus\Registry\CompiledMessageRegistry;
use Fedor\MessageBus\Service\ServiceReference;
use InvalidArgumentException;
use Thesis\Dic;
use Thesis\Dic\Module;
use Thesis\Dic\Ref;

/** @implements Module<Ref<MessageBus>> */
final readonly class ApplicationMessageBusModule implements Module
{
    /**
     * @param Ref<CorrelationContext> $correlationContext
     * @param array<string, Ref<object>> $serviceOverrides
     */
    public function __construct(
        private MessageBusRegistrySource $source,
        private Ref $correlationContext,
        private array $serviceOverrides = [],
    ) {}

    /** @return Ref<MessageBus> */
    public function configure(Dic $dic): Ref
    {
        $registry = (new ApplicationRegistryCompiler())->compile($this->source);
        $serviceTypes = $this->serviceTypes();
        $this->validateOverrides($serviceTypes);
        $this->validateRequirements($registry, $serviceTypes);

        $providers = [];
        foreach ($serviceTypes as $id => $serviceType) {
            $service = $this->serviceOverrides[$id] ?? match ($serviceType) {
                CorrelationContextInterceptor::class => $dic
                    ->object(CorrelationContextInterceptor::class)
                    ->arg('correlationContext', $this->correlationContext),
                default => $dic->object($serviceType)->canBeScoped(),
            };
            $providers[$id] = $dic->provider($service);
        }

        $resolver = $dic
            ->object(ThesisServiceResolver::class)
            ->arg('providers', $dic->value($providers))
            ->scoped();
        $scopeFactory = $dic
            ->object(ThesisServiceScopeFactory::class)
            ->arg('resolver', $dic->scoped($resolver));

        return $dic
            ->object(MessageBus::class)
            ->args([
                'registry' => $dic->value($registry),
                'scopeFactory' => $scopeFactory,
            ]);
    }

    /** @return array<string, class-string<object>> */
    private function serviceTypes(): array
    {
        return array_replace([
            DefaultMessageContextFactory::class => DefaultMessageContextFactory::class,
            SequentialExecutionStrategy::class => SequentialExecutionStrategy::class,
            CorrelationContextInterceptor::class => CorrelationContextInterceptor::class,
        ], $this->source->services);
    }

    /** @param array<string, class-string<object>> $serviceTypes */
    private function validateOverrides(array $serviceTypes): void
    {
        foreach ($this->serviceOverrides as $id => $_service) {
            if (!isset($serviceTypes[$id])) {
                throw new InvalidArgumentException(sprintf(
                    'MessageBus service override `%s` is not declared in the application registry.',
                    $id,
                ));
            }
        }
    }

    /** @param array<string, class-string<object>> $serviceTypes */
    private function validateRequirements(CompiledMessageRegistry $registry, array $serviceTypes): void
    {
        foreach ($registry->compiled()->flows->all() as $flow) {
            if ($flow->contextFactory !== null) {
                $this->validateServiceType(
                    $serviceTypes,
                    new ServiceReference($flow->contextFactory, MessageContextFactoryInterface::class),
                );
            }
            $this->validateServiceType(
                $serviceTypes,
                new ServiceReference($flow->strategy, HandlerExecutionStrategyInterface::class),
            );
            foreach ($flow->middleware as $interceptor) {
                $this->validateServiceType(
                    $serviceTypes,
                    new ServiceReference($interceptor, InterceptorInterface::class),
                );
            }
        }

        foreach ($registry->compiled()->bindings() as $binding) {
            $this->validateServiceType($serviceTypes, $binding->handler);
            foreach ($binding->interceptors as $interceptor) {
                $this->validateServiceType($serviceTypes, $interceptor);
            }
        }
    }

    /**
     * @param array<string, class-string<object>> $serviceTypes
     * @param ServiceReference<object> $reference
     */
    private function validateServiceType(array $serviceTypes, ServiceReference $reference): void
    {
        $serviceType = $serviceTypes[$reference->id] ?? throw new InvalidArgumentException(sprintf(
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
