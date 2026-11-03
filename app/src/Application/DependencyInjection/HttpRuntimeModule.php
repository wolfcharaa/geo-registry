<?php

declare(strict_types=1);

namespace App\Application\DependencyInjection;

use Amp\Http\Client\HttpClient;
use Amp\Postgres\PostgresConnectionPool;
use App\Application\Health\HealthCheckDefinition;
use App\Application\Health\HealthCheckPolicy;
use App\Application\Health\HealthRegistry;
use App\Application\Health\StartupState;
use App\Application\Runtime\HttpRuntime;
use App\Config\EnvironmentSnapshot;
use App\Config\HttpServerConfig;
use App\Config\NominatimConfig;
use App\Config\OpenSearchConfig;
use App\Config\PostgresConfig;
use App\Infrastructure\Http\AmpHttpClientFactory;
use App\Infrastructure\Logging\ConsoleLoggerFactory;
use App\Infrastructure\Nominatim\NominatimClient;
use App\Infrastructure\Nominatim\NominatimHealthCheck;
use App\Infrastructure\OpenSearch\OpenSearchClient;
use App\Infrastructure\OpenSearch\OpenSearchClientFactory;
use App\Infrastructure\OpenSearch\OpenSearchHealthCheck;
use App\Infrastructure\Postgres\PostgresHealthCheck;
use App\Infrastructure\Postgres\PostgresPoolFactory;
use App\UserInterface\Http\RouterFactory;
use Monolog\Logger;
use Thesis\Dic;
use Thesis\Dic\Module;
use Thesis\Dic\Ref;

/** @implements Module<Ref<HttpRuntime>> */
final readonly class HttpRuntimeModule implements Module
{
    public function __construct(private EnvironmentSnapshot $environment) {}

    /** @return Ref<HttpRuntime> */
    public function configure(Dic $dic): Ref
    {
        $httpConfig = HttpServerConfig::fromEnvironment($this->environment);
        $postgresConfig = PostgresConfig::fromEnvironment($this->environment);
        $nominatimConfig = NominatimConfig::fromEnvironment($this->environment);
        $openSearchConfig = OpenSearchConfig::fromEnvironment($this->environment);

        $logger = $dic->object(Logger::class, ConsoleLoggerFactory::create(...));
        $httpClient = $dic
            ->object(
                HttpClient::class,
                static fn(): HttpClient => AmpHttpClientFactory::create($httpConfig->timeoutSeconds),
            );
        $postgres = $dic
            ->object(
                PostgresConnectionPool::class,
                static fn(): PostgresConnectionPool => PostgresPoolFactory::create(
                    $postgresConfig->dsn,
                    $postgresConfig->poolSize,
                ),
            )
            ->disposer(static fn(PostgresConnectionPool $pool) => $pool->close());
        $nominatim = $dic
            ->object(NominatimClient::class)
            ->args([
                'httpClient' => $httpClient,
                'baseUrl' => $nominatimConfig->url,
                'userAgent' => $httpConfig->userAgent,
                'language' => $nominatimConfig->language,
            ]);
        $openSearchFactory = $dic
            ->object(OpenSearchClientFactory::class)
            ->arg('httpClient', $httpClient);
        $openSearch = $dic
            ->object(OpenSearchClient::class, [$openSearchFactory, 'create'])
            ->args([
                'baseUrl' => $openSearchConfig->url,
                'userAgent' => $httpConfig->userAgent,
                'username' => $openSearchConfig->username,
                'password' => $openSearchConfig->password,
            ]);
        $startupState = $dic->object(StartupState::class);
        $postgresHealth = $dic
            ->object(PostgresHealthCheck::class)
            ->arg('postgres', $postgres);
        $nominatimHealth = $dic
            ->object(NominatimHealthCheck::class)
            ->arg('client', $nominatim);
        $openSearchHealth = $dic
            ->object(OpenSearchHealthCheck::class)
            ->arg('client', $openSearch);
        $postgresHealthDefinition = $dic
            ->object(HealthCheckDefinition::class)
            ->args([
                'name' => 'postgres',
                'policy' => HealthCheckPolicy::Required,
                'deadlineSeconds' => 2,
                'check' => $postgresHealth,
            ]);
        $nominatimHealthDefinition = $dic
            ->object(HealthCheckDefinition::class)
            ->args([
                'name' => 'nominatim',
                'policy' => HealthCheckPolicy::Optional,
                'deadlineSeconds' => 2,
                'check' => $nominatimHealth,
            ]);
        $openSearchHealthDefinition = $dic
            ->object(HealthCheckDefinition::class)
            ->args([
                'name' => 'opensearch',
                'policy' => HealthCheckPolicy::Optional,
                'deadlineSeconds' => 2,
                'check' => $openSearchHealth,
            ]);
        $health = $dic
            ->object(HealthRegistry::class)
            ->args([
                'checks' => [
                    $postgresHealthDefinition,
                    $nominatimHealthDefinition,
                    $openSearchHealthDefinition,
                ],
                'startupState' => $startupState,
                'logger' => $logger,
            ]);
        $routerFactory = $dic
            ->object(RouterFactory::class)
            ->args([
                'logger' => $logger,
                'health' => $health,
                'nominatim' => $nominatim,
                'openSearch' => $openSearch,
                'openSearchIndex' => $openSearchConfig->index,
            ]);

        return $dic
            ->object(HttpRuntime::class)
            ->args([
                'config' => $dic->value($httpConfig),
                'logger' => $logger,
                'routerFactory' => $routerFactory,
                'startupState' => $startupState,
            ]);
    }
}
