<?php

declare(strict_types=1);

namespace App\Application\DependencyInjection;

use Amp\Http\Client\HttpClient;
use Amp\Postgres\PostgresConnectionPool;
use App\Application\Health\HealthCheckDefinition;
use App\Application\Health\HealthCheckPolicy;
use App\Application\Health\HealthRegistry;
use App\Application\Health\StartupState;
use App\Application\MessageBus\MessageBusRegistrySource;
use App\Application\Observability\CorrelationContext;
use App\Application\Runtime\HttpRuntime;
use App\Config\EnvironmentSnapshot;
use App\Config\HttpServerConfig;
use App\Config\LoggingConfig;
use App\Config\NominatimConfig;
use App\Config\OpenSearchConfig;
use App\Config\PostgresConfig;
use App\Infrastructure\Http\AmpHttpClientFactory;
use App\Infrastructure\Logging\StdoutLoggerFactory;
use App\Infrastructure\Nominatim\NominatimClient;
use App\Infrastructure\Nominatim\NominatimHealthCheck;
use App\Infrastructure\OpenSearch\OpenSearchClient;
use App\Infrastructure\OpenSearch\OpenSearchClientFactory;
use App\Infrastructure\OpenSearch\OpenSearchHealthCheck;
use App\Infrastructure\Postgres\PostgresHealthCheck;
use App\Infrastructure\Postgres\PostgresPoolFactory;
use App\Module\AddressRegistry\UserInterface\Http\AddressSuggestionPresenter;
use App\Module\AddressRegistry\UserInterface\Http\FindAddressById\FindAddressByIdAction;
use App\Module\AddressRegistry\UserInterface\Http\FindAddressById\FindAddressByIdPresenter;
use App\Module\AddressRegistry\UserInterface\Http\FindAddressById\FindAddressByIdRequestMapper;
use App\Module\AddressRegistry\UserInterface\Http\SearchAddress\SearchAddressAction;
use App\Module\AddressRegistry\UserInterface\Http\SearchAddress\SearchAddressPresenter;
use App\Module\AddressRegistry\UserInterface\Http\SearchAddress\SearchAddressRequestMapper;
use App\Module\AddressRegistry\UserInterface\Http\SuggestAddress\SuggestAddressAction;
use App\Module\AddressRegistry\UserInterface\Http\SuggestAddress\SuggestAddressPresenter;
use App\Module\AddressRegistry\UserInterface\Http\SuggestAddress\SuggestAddressRequestMapper;
use App\UserInterface\Http\CorrelationMiddleware;
use App\UserInterface\Http\RouterFactory;
use Monolog\Logger;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Thesis\Dic;
use Thesis\Dic\Module;
use Thesis\Dic\Ref;

/** @implements Module<Ref<HttpRuntime>> */
final readonly class HttpRuntimeModule implements Module
{
    public function __construct(
        private EnvironmentSnapshot $environment,
        private ?MessageBusRegistrySource $messageBusRegistry = null,
    ) {}

    /** @return Ref<HttpRuntime> */
    public function configure(Dic $dic): Ref
    {
        $httpConfig = HttpServerConfig::fromEnvironment($this->environment);
        $loggingConfig = LoggingConfig::fromEnvironment($this->environment);
        $postgresConfig = PostgresConfig::fromEnvironment($this->environment);
        $nominatimConfig = NominatimConfig::fromEnvironment($this->environment);
        $openSearchConfig = OpenSearchConfig::fromEnvironment($this->environment);
        $registrySource = $this->messageBusRegistry ?? MessageBusRegistrySource::empty();

        $correlationContext = $dic->object(CorrelationContext::class);
        $loggerFactory = $dic
            ->object(StdoutLoggerFactory::class)
            ->arg('context', $correlationContext);
        $logger = $dic
            ->object(Logger::class, [$loggerFactory, 'create'])
            ->args([
                'config' => $dic->value($loggingConfig),
                'runtime' => 'http',
            ]);
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
        $messageBus = $dic->import(new ApplicationMessageBusModule(
            $registrySource,
            $correlationContext,
            $logger,
            AddressRegistryMessageBusServices::configure($dic, $registrySource, $postgres),
        ));
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
                'correlationContext' => $correlationContext,
            ]);
        $correlationMiddleware = $dic
            ->object(CorrelationMiddleware::class)
            ->args([
                'context' => $correlationContext,
                'logger' => $logger,
            ]);
        $validator = $dic->object(
            ValidatorInterface::class,
            static fn(): ValidatorInterface => Validation::createValidatorBuilder()
                ->enableAttributeMapping()
                ->getValidator(),
        );
        $findAddressByIdRequestMapper = $dic
            ->object(FindAddressByIdRequestMapper::class)
            ->arg('validator', $validator);
        $suggestionPresenter = $dic->object(AddressSuggestionPresenter::class);
        $findAddressById = $dic
            ->object(FindAddressByIdAction::class)
            ->args([
                'requestMapper' => $findAddressByIdRequestMapper,
                'presenter' => $dic
                    ->object(FindAddressByIdPresenter::class)
                    ->arg('suggestions', $suggestionPresenter),
                'messageBus' => $messageBus,
                'timeoutSeconds' => $httpConfig->timeoutSeconds,
            ]);
        $searchAddressRequestMapper = $dic
            ->object(SearchAddressRequestMapper::class)
            ->arg('validator', $validator);
        $searchAddress = $dic
            ->object(SearchAddressAction::class)
            ->args([
                'requestMapper' => $searchAddressRequestMapper,
                'presenter' => $dic
                    ->object(SearchAddressPresenter::class)
                    ->arg('suggestions', $suggestionPresenter),
                'messageBus' => $messageBus,
                'timeoutSeconds' => $httpConfig->timeoutSeconds,
            ]);
        $suggestAddressRequestMapper = $dic
            ->object(SuggestAddressRequestMapper::class)
            ->arg('validator', $validator);
        $suggestAddress = $dic
            ->object(SuggestAddressAction::class)
            ->args([
                'requestMapper' => $suggestAddressRequestMapper,
                'presenter' => $dic
                    ->object(SuggestAddressPresenter::class)
                    ->arg('documents', $suggestionPresenter),
                'messageBus' => $messageBus,
                'timeoutSeconds' => $httpConfig->timeoutSeconds,
            ]);
        $routerFactory = $dic
            ->object(RouterFactory::class)
            ->args([
                'logger' => $logger,
                'health' => $health,
                'nominatim' => $nominatim,
                'openSearch' => $openSearch,
                'openSearchIndex' => $openSearchConfig->index,
                'correlationMiddleware' => $correlationMiddleware,
                'findAddressById' => $findAddressById,
                'searchAddress' => $searchAddress,
                'suggestAddress' => $suggestAddress,
            ]);

        return $dic
            ->object(HttpRuntime::class)
            ->args([
                'config' => $dic->value($httpConfig),
                'logger' => $logger,
                'routerFactory' => $routerFactory,
                'startupState' => $startupState,
                'messageBus' => $messageBus,
            ]);
    }
}
