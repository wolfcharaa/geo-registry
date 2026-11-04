<?php

declare(strict_types=1);

namespace App\Application\DependencyInjection;

use Amp\Postgres\PostgresConnectionPool;
use App\Application\MessageBus\ApplicationRegistryCompiler;
use App\Application\MessageBus\MessageBusRegistrySource;
use App\Application\MessageBus\MessageBusServiceMap;
use App\Application\Observability\CorrelationContext;
use Fedor\MessageBus\Clock\SystemClock;
use Fedor\MessageBus\Codec\MessageCodecRegistry;
use Fedor\MessageBus\Envelope\JsonEnvelopeSerializer;
use Fedor\MessageBus\Integration\Thesis\MessageBusModule;
use Fedor\MessageBus\Integration\Thesis\ThesisServiceResolver;
use Fedor\MessageBus\Integration\Thesis\ThesisServiceScopeFactory;
use Fedor\MessageBus\MessageBus;
use Fedor\MessageBus\Queue\Postgres\PostgresQueueGateway;
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
     * @param Ref<PostgresConnectionPool>|null $postgres
     */
    public function __construct(
        private MessageBusRegistrySource $source,
        private Ref $correlationContext,
        private Ref $logger,
        private array $serviceOverrides = [],
        private ?Ref $postgres = null,
        private ?MessageCodecRegistry $messageCodecs = null,
    ) {}

    /** @return Ref<MessageBus> */
    public function configure(Dic $dic): Ref
    {
        $registry = (new ApplicationRegistryCompiler($this->messageCodecs))->compile($this->source);
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

        $serializer = null;
        $clock = null;
        $queueProvider = null;
        if ($this->postgres !== null) {
            $codecs = $this->messageCodecs ?? new MessageCodecRegistry([]);
            $serializer = $dic
                ->object(JsonEnvelopeSerializer::class)
                ->arg('codecs', $dic->value($codecs));
            $clock = $dic->object(SystemClock::class);
            $queueProvider = $dic
                ->object(PostgresQueueGateway::class)
                ->arg('postgres', $this->postgres);
        }

        return $dic->import(new MessageBusModule(
            registry: $dic->value($registry),
            scopeFactory: $scopeFactory,
            envelopeSerializer: $serializer,
            clock: $clock,
            queueProvider: $queueProvider,
        ));
    }
}
