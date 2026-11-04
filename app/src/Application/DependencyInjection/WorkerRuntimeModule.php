<?php

declare(strict_types=1);

namespace App\Application\DependencyInjection;

use Amp\Postgres\PostgresConnectionPool;
use App\Application\MessageBus\ApplicationRegistryCompiler;
use App\Application\MessageBus\MessageBusRegistrySource;
use App\Application\MessageBus\MessageBusServiceMap;
use App\Application\Observability\CorrelationContext;
use App\Application\Worker\WorkerJobServiceResolver;
use App\Config\EnvironmentSnapshot;
use App\Config\LoggingConfig;
use App\Config\PostgresConfig;
use App\Infrastructure\Logging\StdoutLoggerFactory;
use App\Infrastructure\Postgres\PostgresPoolFactory;
use Fedor\MessageBus\Codec\MessageCodecRegistry;
use Fedor\MessageBus\Envelope\JsonEnvelopeSerializer;
use Fedor\MessageBus\Integration\Thesis\ThesisServiceResolver;
use Fedor\MessageBus\Integration\Thesis\ThesisServiceScopeFactory;
use Fedor\MessageBus\Worker\Control\DefaultWorkerControlService;
use Fedor\MessageBus\Worker\Control\DefaultWorkerStatusService;
use Fedor\MessageBus\Worker\Control\Postgres\PostgresWorkerControlGateway;
use Fedor\MessageBus\Worker\Control\WorkerControlRuntime;
use Fedor\MessageBus\Worker\Queue\Postgres\PostgresMessageConsumer;
use Fedor\MessageBus\Worker\Queue\QueueWorkerRunner;
use Fedor\MessageBus\Worker\Runtime\WorkerRuntime;
use Monolog\Logger;
use Thesis\Dic;
use Thesis\Dic\Module;
use Thesis\Dic\Ref;

/** @implements Module<Ref<WorkerRuntime>> */
final readonly class WorkerRuntimeModule implements Module
{
    public function __construct(
        private EnvironmentSnapshot $environment,
        private ?MessageBusRegistrySource $messageBusRegistry = null,
        private ?MessageCodecRegistry $messageCodecs = null,
    ) {}

    /** @return Ref<WorkerRuntime> */
    public function configure(Dic $dic): Ref
    {
        $loggingConfig = LoggingConfig::fromEnvironment($this->environment);
        $postgresConfig = PostgresConfig::fromEnvironment($this->environment);
        $registrySource = $this->messageBusRegistry ?? MessageBusRegistrySource::empty();
        $registry = (new ApplicationRegistryCompiler())->compile($registrySource);

        $correlationContext = $dic->object(CorrelationContext::class);
        $loggerFactory = $dic
            ->object(StdoutLoggerFactory::class)
            ->arg('context', $correlationContext);
        $logger = $dic
            ->object(Logger::class, [$loggerFactory, 'create'])
            ->args([
                'config' => $dic->value($loggingConfig),
                'runtime' => 'worker',
            ]);
        $messageBus = $dic->import(new ApplicationMessageBusModule(
            $registrySource,
            $correlationContext,
            $logger,
        ));
        $postgres = $dic
            ->object(
                PostgresConnectionPool::class,
                static fn(): PostgresConnectionPool => PostgresPoolFactory::create(
                    $postgresConfig->dsn,
                    $postgresConfig->poolSize,
                ),
            )
            ->disposer(static fn(PostgresConnectionPool $pool) => $pool->close());
        $consumer = $dic
            ->object(PostgresMessageConsumer::class)
            ->arg('postgres', $postgres);
        $controlGateway = $dic
            ->object(PostgresWorkerControlGateway::class)
            ->arg('postgres', $postgres);
        $controlService = $dic
            ->object(DefaultWorkerControlService::class)
            ->args([
                'commands' => $controlGateway,
                'desiredStates' => $controlGateway,
            ]);
        $statusService = $dic
            ->object(DefaultWorkerStatusService::class)
            ->arg('repository', $controlGateway);
        $controlRuntime = $dic
            ->object(WorkerControlRuntime::class)
            ->args([
                'controlService' => $controlService,
                'commandRepository' => $controlGateway,
                'desiredStateRepository' => $controlGateway,
                'workerRegistry' => $controlGateway,
                'statusRepository' => $controlGateway,
                'inbox' => $controlGateway,
                'statusService' => $statusService,
            ]);
        $serializer = $dic
            ->object(JsonEnvelopeSerializer::class)
            ->arg('codecs', $dic->value($this->messageCodecs ?? new MessageCodecRegistry([])));
        $providers = (new ThesisMessageBusServiceProviders(
            MessageBusServiceMap::from($registrySource, $registry),
            $correlationContext,
            $logger,
        ))->configure($dic);
        $messageServices = $dic
            ->object(ThesisServiceResolver::class)
            ->arg('providers', $dic->value($providers))
            ->scoped();
        $jobServices = $dic
            ->object(WorkerJobServiceResolver::class)
            ->args([
                'services' => $messageServices,
                'executor' => $messageBus,
                'serializer' => $serializer,
                'correlationContext' => $correlationContext,
            ])
            ->scoped();
        $scopeFactory = $dic
            ->object(ThesisServiceScopeFactory::class)
            ->arg('resolver', $dic->scoped($jobServices));
        $runner = $dic
            ->object(QueueWorkerRunner::class)
            ->args([
                'consumer' => $consumer,
                'leaseControl' => $consumer,
                'scopeFactory' => $scopeFactory,
                'workerControlRuntime' => $controlRuntime,
            ]);

        return $dic
            ->object(WorkerRuntime::class)
            ->args([
                'runner' => $runner,
                'control' => $controlRuntime,
            ]);
    }
}
