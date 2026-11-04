<?php

declare(strict_types=1);

namespace App\Application\DependencyInjection;

use Amp\Postgres\PostgresConnectionPool;
use App\Application\Console\Command\MessageBusRegistryCheckCommand;
use App\Application\MessageBus\ApplicationRegistryCompiler;
use App\Application\MessageBus\MessageBusRegistrySource;
use App\Application\Observability\CorrelationContext;
use App\Application\Runtime\CliRuntime;
use App\Config\ConsoleConfig;
use App\Config\EnvironmentSnapshot;
use App\Config\LoggingConfig;
use App\Config\OpenSearchConfig;
use App\Config\PostgresConfig;
use App\Infrastructure\Logging\StdoutLoggerFactory;
use App\Infrastructure\Postgres\PostgresPoolFactory;
use Monolog\Logger;
use Symfony\Component\Console\Application;
use Thesis\Dic;
use Thesis\Dic\Module;
use Thesis\Dic\Ref;

/** @implements Module<Ref<CliRuntime>> */
final readonly class CliRuntimeModule implements Module
{
    public function __construct(
        private EnvironmentSnapshot $environment,
        private ?MessageBusRegistrySource $messageBusRegistry = null,
    ) {}

    /** @return Ref<CliRuntime> */
    public function configure(Dic $dic): Ref
    {
        $config = ConsoleConfig::fromEnvironment($this->environment);
        $loggingConfig = LoggingConfig::fromEnvironment($this->environment);
        $postgresConfig = PostgresConfig::fromEnvironment($this->environment);
        $openSearchConfig = OpenSearchConfig::fromEnvironment($this->environment);
        $source = $this->messageBusRegistry ?? MessageBusRegistrySource::empty();
        $application = $dic->object(
            Application::class,
            static fn(): Application => new Application($config->name, $config->version),
        );
        $registrySource = $dic->value($source);
        $correlationContext = $dic->object(CorrelationContext::class);
        $loggerFactory = $dic
            ->object(StdoutLoggerFactory::class)
            ->arg('context', $correlationContext);
        $logger = $dic
            ->object(Logger::class, [$loggerFactory, 'create'])
            ->args([
                'config' => $dic->value($loggingConfig),
                'runtime' => 'cli',
            ]);
        $postgres = $dic
            ->object(
                PostgresConnectionPool::class,
                static fn(): PostgresConnectionPool => PostgresPoolFactory::create(
                    $postgresConfig->dsn,
                    $postgresConfig->poolSize,
                ),
            )
            ->disposer(static fn(PostgresConnectionPool $pool) => $pool->close());
        $messageBus = $dic->import(new ApplicationMessageBusModule(
            $source,
            $correlationContext,
            $logger,
            AddressRegistryMessageBusServices::configure($dic, $source, $postgres),
        ));
        $registryCompiler = $dic->object(ApplicationRegistryCompiler::class);
        $registryCheck = $dic
            ->object(MessageBusRegistryCheckCommand::class)
            ->args([
                'source' => $registrySource,
                'compiler' => $registryCompiler,
            ]);
        $application->call('addCommand', [$registryCheck]);
        $application->call('addCommands', [
            $dic->import(new MigrationCliModule($postgresConfig)),
        ]);
        $application->call('addCommand', [
            $dic->import(new GarImportCliModule($postgresConfig)),
        ]);
        $application->call('addCommand', [
            $dic->import(new AddressSearchRebuildCliModule($postgresConfig)),
        ]);
        $application->call('addCommands', [
            $dic->import(new OpenSearchProjectionCliModule($postgres, $config, $openSearchConfig)),
        ]);

        return $dic
            ->object(CliRuntime::class)
            ->args([
                'application' => $application,
                'messageBus' => $messageBus,
            ]);
    }
}
