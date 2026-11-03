<?php

declare(strict_types=1);

namespace App;

use Amp\ByteStream;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\SocketHttpServer;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;

use function Amp\trapSignal;

use App\Config\EnvironmentSnapshot;
use App\Config\HttpServerConfig;
use App\Config\NominatimConfig;
use App\Config\OpenSearchConfig;
use App\Config\PostgresConfig;
use App\Infrastructure\Http\AmpHttpClientFactory;
use App\Infrastructure\Nominatim\NominatimClient;
use App\Infrastructure\OpenSearch\OpenSearchClientFactory;
use App\Infrastructure\Postgres\PostgresPoolFactory;
use App\UserInterface\Http\RouterFactory;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;

final class Bootstrap
{
    public static function run(): void
    {
        $environment = EnvironmentSnapshot::capture();
        $httpConfig = HttpServerConfig::fromEnvironment($environment);
        $postgresConfig = PostgresConfig::fromEnvironment($environment);
        $nominatimConfig = NominatimConfig::fromEnvironment($environment);
        $openSearchConfig = OpenSearchConfig::fromEnvironment($environment);
        $logger = self::logger();
        $httpClient = AmpHttpClientFactory::create($httpConfig->timeoutSeconds);
        $postgres = PostgresPoolFactory::create($postgresConfig->dsn, $postgresConfig->poolSize);
        $nominatim = new NominatimClient(
            $httpClient,
            $nominatimConfig->url,
            $httpConfig->userAgent,
            $nominatimConfig->language,
        );
        $openSearch = (new OpenSearchClientFactory($httpClient))->create(
            $openSearchConfig->url,
            $httpConfig->userAgent,
            $openSearchConfig->username,
            $openSearchConfig->password,
        );
        $errorHandler = new DefaultErrorHandler();
        $server = SocketHttpServer::createForDirectAccess($logger);
        $router = (new RouterFactory($logger, $postgres, $nominatim, $openSearch, $openSearchConfig->index))
            ->create($server, $errorHandler);

        $server->expose($httpConfig->listen);
        $server->start($router, $errorHandler);
        $logger->info('HTTP server started', ['listen' => $httpConfig->listen]);

        try {
            $signal = trapSignal([SIGINT, SIGTERM]);
            $logger->info('Stopping HTTP server', ['signal' => $signal]);
        } finally {
            $server->stop();
            $postgres->close();
        }
    }

    private static function logger(): Logger
    {
        $handler = new StreamHandler(ByteStream\getStdout());
        $handler->setFormatter(new ConsoleFormatter());

        $logger = new Logger('app');
        $logger->pushHandler($handler);
        $logger->pushProcessor(new PsrLogMessageProcessor());

        return $logger;
    }
}
