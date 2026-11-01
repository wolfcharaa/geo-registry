<?php

declare(strict_types=1);

namespace App;

use Amp\ByteStream;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\SocketHttpServer;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;

use function Amp\trapSignal;

use App\Config\AppConfig;
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
        $config = AppConfig::fromEnvironment();
        $logger = self::logger();
        $httpClient = AmpHttpClientFactory::create($config->httpTimeoutSeconds);
        $postgres = PostgresPoolFactory::create($config->databaseDsn, $config->databasePoolSize);
        $nominatim = new NominatimClient(
            $httpClient,
            $config->nominatimUrl,
            $config->userAgent,
            $config->nominatimLanguage,
        );
        $openSearch = (new OpenSearchClientFactory($httpClient))->create(
            $config->openSearchUrl,
            $config->userAgent,
            $config->openSearchUsername,
            $config->openSearchPassword,
        );
        $errorHandler = new DefaultErrorHandler();
        $server = SocketHttpServer::createForDirectAccess($logger);
        $router = (new RouterFactory($logger, $postgres, $nominatim, $openSearch, $config->openSearchIndex))
            ->create($server, $errorHandler);

        $server->expose($config->listen);
        $server->start($router, $errorHandler);
        $logger->info('HTTP server started', ['listen' => $config->listen]);

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
