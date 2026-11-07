<?php

declare(strict_types=1);

namespace App\Application\DependencyInjection;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Postgres\PostgresConnectionPool;
use App\Application\Console\Command\HttpLoadCheckCommand;
use App\Application\Console\Command\HttpLoadCheckRunner;
use App\Application\Console\Command\MessageBusRegistryCheckCommand;
use App\Application\Console\Command\RunHttpServerCommand;
use App\Application\MessageBus\ApplicationRegistryCompiler;
use App\Application\MessageBus\MessageBusRegistrySource;
use App\Application\Observability\CorrelationContext;
use App\Application\Runtime\CliRuntime;
use App\Application\Runtime\HttpClusterRuntime;
use App\Config\AddressSourceConfig;
use App\Config\ConsoleConfig;
use App\Config\EnvironmentSnapshot;
use App\Config\HttpServerConfig;
use App\Config\LoggingConfig;
use App\Config\OpenSearchConfig;
use App\Config\PostgresConfig;
use App\Infrastructure\Logging\StdoutLoggerFactory;
use App\Infrastructure\Postgres\PostgresPoolFactory;
use Fedor\MessageBus\Codec\MessageCodecRegistry;
use Monolog\Logger;
use Thesis\Dic;
use Thesis\Dic\Module;
use Thesis\Dic\Ref;
use Thesis\SymfonyConsoleModule;
use Thesis\SymfonyConsoleModule\CommandTag;
use Thesis\SymfonyConsoleModule\LegacyCommandTag;

/** @implements Module<Ref<CliRuntime>> */
final readonly class CliRuntimeModule implements Module
{
    public function __construct(
        private EnvironmentSnapshot $environment,
        private ?MessageBusRegistrySource $messageBusRegistry = null,
        private ?MessageCodecRegistry $messageCodecs = null,
        private ?MessageBusRegistrySource $httpMessageBusRegistry = null,
    ) {}

    /** @return Ref<CliRuntime> */
    public function configure(Dic $dic): Ref
    {
        $config = ConsoleConfig::fromEnvironment($this->environment);
        $httpConfig = HttpServerConfig::fromEnvironment($this->environment);
        $loggingConfig = LoggingConfig::fromEnvironment($this->environment);
        $addressSourceConfig = AddressSourceConfig::fromEnvironment($this->environment);
        $postgresConfig = PostgresConfig::fromEnvironment($this->environment);
        $openSearchConfig = OpenSearchConfig::fromEnvironment($this->environment);
        $source = $this->messageBusRegistry ?? MessageBusRegistrySource::empty();
        $codecs = $this->messageCodecs ?? new MessageCodecRegistry([]);
        $registrySource = $dic->value($source);
        $postgres = $dic
            ->object(
                PostgresConnectionPool::class,
                static fn(): PostgresConnectionPool => PostgresPoolFactory::create(
                    $postgresConfig->dsn,
                    $postgresConfig->poolSize,
                ),
            )
            ->disposer(static fn(PostgresConnectionPool $pool) => $pool->close());
        $registryCompiler = $dic
            ->object(ApplicationRegistryCompiler::class)
            ->arg('codecs', $dic->value($codecs));
        $dic
            ->object(MessageBusRegistryCheckCommand::class)
            ->args([
                'source' => $registrySource,
                'compiler' => $registryCompiler,
            ])
            ->tag(new LegacyCommandTag(
                'message-bus:registry:check',
                'Compile and validate the explicit application MessageBus registry.',
            ));
        $httpRuntime = $dic->import(new HttpRuntimeModule(
            $this->environment,
            $this->httpMessageBusRegistry ?? MessageBusRegistrySource::empty(),
            $codecs,
        ));
        $clusterCorrelationContext = $dic->object(CorrelationContext::class);
        $clusterLoggerFactory = $dic
            ->object(StdoutLoggerFactory::class)
            ->arg('context', $clusterCorrelationContext);
        $clusterLogger = $dic
            ->object(Logger::class, [$clusterLoggerFactory, 'create'])
            ->args([
                'config' => $dic->value($loggingConfig),
                'runtime' => 'http-supervisor',
            ]);
        $clusterRuntime = $dic
            ->object(HttpClusterRuntime::class)
            ->args([
                'config' => $dic->value($httpConfig),
                'logger' => $clusterLogger,
                'entrypoint' => $dic->value(dirname(__DIR__, 3) . '/bin/app'),
            ]);
        $dic
            ->object(RunHttpServerCommand::class)
            ->args([
                'config' => $dic->value($httpConfig),
                'runtimeProvider' => $dic->provider($httpRuntime),
                'clusterRuntimeProvider' => $dic->provider($clusterRuntime),
            ])
            ->tag(new CommandTag('server:run', 'Run the AMPHP HTTP server.'));
        $loadHttpClient = $dic->object(
            HttpClient::class,
            static fn(): HttpClient => (new HttpClientBuilder())
                ->retry(0)
                ->followRedirects(0)
                ->build(),
        );
        $loadRunner = $dic
            ->object(HttpLoadCheckRunner::class)
            ->args([
                'httpClient' => $loadHttpClient,
                'userAgent' => $dic->value($httpConfig->userAgent),
            ]);
        $dic
            ->object(HttpLoadCheckCommand::class)
            ->arg('runner', $loadRunner)
            ->tag(new LegacyCommandTag(
                'server:load-check',
                'Run a bounded concurrent HTTP load gate without retries.',
            ));

        $dic->import(new MigrationCliModule($postgresConfig));
        $dic->import(new GarImportCliModule($postgresConfig));
        $dic->import(new GarStatusCliModule($postgres));
        $dic->import(new GarIntegrityCliModule($postgres));
        $dic->import(new GarActivityCliModule($postgres));
        $dic->import(new GarReleasesCliModule($config));
        $dic->import(new AddressSearchRebuildCliModule($postgresConfig));
        $dic->import(new AddressSourceCliModule($postgresConfig, $addressSourceConfig, $config));
        $dic->import(new OpenSearchProjectionCliModule($postgres, $config, $openSearchConfig));
        $dic->import(new WorkerCliModule());

        $application = $dic->import(new SymfonyConsoleModule(
            name: $config->name,
            version: $config->version,
        ));

        return $dic
            ->object(CliRuntime::class)
            ->arg('application', $application);
    }
}
