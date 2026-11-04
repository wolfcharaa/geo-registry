<?php

declare(strict_types=1);

namespace App\Application\DependencyInjection;

use App\Application\MessageBus\ApplicationRegistryCompiler;
use App\Application\MessageBus\MessageBusRegistrySource;
use App\Application\MessageBus\MessageBusServiceMap;
use App\Application\Observability\CorrelationContext;
use Fedor\MessageBus\Integration\Thesis\ThesisServiceResolver;
use Fedor\MessageBus\Integration\Thesis\ThesisServiceScopeFactory;
use Fedor\MessageBus\MessageBus;
use Psr\Log\LoggerInterface;
use Thesis\Dic;
use Thesis\Dic\Module;
use Thesis\Dic\Ref;

/** @implements Module<Ref<MessageBus>> */
final readonly class ApplicationMessageBusModule implements Module
{
    /**
     * @param Ref<CorrelationContext> $correlationContext
     * @param Ref<LoggerInterface> $logger
     * @param array<string, Ref<object>> $serviceOverrides
     */
    public function __construct(
        private MessageBusRegistrySource $source,
        private Ref $correlationContext,
        private Ref $logger,
        private array $serviceOverrides = [],
    ) {}

    /** @return Ref<MessageBus> */
    public function configure(Dic $dic): Ref
    {
        $registry = (new ApplicationRegistryCompiler())->compile($this->source);
        $providers = (new ThesisMessageBusServiceProviders(
            MessageBusServiceMap::from($this->source, $registry),
            $this->correlationContext,
            $this->logger,
            $this->serviceOverrides,
        ))->configure($dic);

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

}
