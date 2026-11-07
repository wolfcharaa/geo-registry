<?php

declare(strict_types=1);

namespace App\UserInterface\Http;

use Amp\Cluster\Cluster;
use Amp\Http\Server\Driver\ClientFactory;
use Amp\Http\Server\Driver\ConnectionLimitingClientFactory;
use Amp\Http\Server\Driver\ConnectionLimitingServerSocketFactory;
use Amp\Http\Server\Driver\SocketClientFactory;
use Amp\Http\Server\Middleware\AllowedMethodsMiddleware;
use Amp\Http\Server\Middleware\CompressionMiddleware;
use Amp\Http\Server\Middleware\ConcurrencyLimitingMiddleware;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\BindContext;
use Amp\Sync\LocalSemaphore;
use App\Config\HttpServerConfig;
use Psr\Log\LoggerInterface;

final readonly class AmpHttpServerFactory
{
    public function __construct(
        private HttpServerConfig $config,
        private LoggerInterface $logger,
    ) {}

    public function create(): SocketHttpServer
    {
        $serverSocketFactory = new ConnectionLimitingServerSocketFactory(
            new LocalSemaphore($this->config->connectionLimit()),
            Cluster::getServerSocketFactory(),
        );
        $this->logger->notice('HTTP client connection limit configured', [
            'event' => 'http.server.connection_limit.configured',
            'limit' => $this->config->connectionLimit,
        ]);

        $clientFactory = $this->clientFactory();
        $middleware = [];
        if ($this->config->concurrencyLimit > 0) {
            $middleware[] = new ConcurrencyLimitingMiddleware($this->config->concurrencyLimit);
        }
        if ($this->config->compressionEnabled && extension_loaded('zlib')) {
            $middleware[] = new CompressionMiddleware();
        }
        if ($this->config->workerMaxRequests > 0 || $this->config->workerMaxMemoryMegabytes > 0) {
            $middleware[] = new HttpWorkerRecycleMiddleware(
                $this->config->workerMaxRequests,
                $this->config->workerMaxMemoryBytes(),
                $this->logger,
            );
        }

        return new SocketHttpServer(
            $this->logger,
            $serverSocketFactory,
            $clientFactory,
            $middleware,
            AllowedMethodsMiddleware::DEFAULT_ALLOWED_METHODS,
        );
    }

    public function bindContext(): BindContext
    {
        return (new BindContext())
            ->withBacklog($this->config->backlog())
            ->withTcpNoDelay();
    }

    private function clientFactory(): ClientFactory
    {
        $clientFactory = new SocketClientFactory($this->logger);
        if ($this->config->connectionsPerIpLimit === 0) {
            return $clientFactory;
        }

        return new ConnectionLimitingClientFactory(
            $clientFactory,
            $this->logger,
            $this->config->connectionsPerIpLimit,
        );
    }
}
