<?php

declare(strict_types=1);

namespace App\Application\Runtime;

use Amp\Cluster\Cluster;
use App\Application\Health\StartupState;
use App\Config\HttpServerConfig;
use App\UserInterface\Http\AmpHttpServerFactory;
use App\UserInterface\Http\JsonErrorHandler;
use App\UserInterface\Http\RouterFactory;
use Fedor\MessageBus\MessageBusInterface;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use RuntimeException;

final readonly class HttpRuntime
{
    public function __construct(
        private HttpServerConfig $config,
        private LoggerInterface $logger,
        private RouterFactory $routerFactory,
        private StartupState $startupState,
        private MessageBusInterface $messageBus,
        private AmpHttpServerFactory $serverFactory,
    ) {}

    public function run(): void
    {
        $eventLoopDriver = EventLoop::getDriver()::class;
        if (str_ends_with($eventLoopDriver, '\\EvDriver') && !extension_loaded('pq')) {
            throw new RuntimeException('ext-pq is required when the Revolt event loop uses ext-ev.');
        }

        $errorHandler = new JsonErrorHandler();
        $server = $this->serverFactory->create();
        $router = $this->routerFactory->create($server, $errorHandler);

        $server->expose($this->config->listen, $this->serverFactory->bindContext());
        $server->start($router, $errorHandler);
        $this->startupState->markComplete();
        $this->logger->info('HTTP server started', [
            'event' => 'http.server.started',
            'listen' => $this->config->listen,
            'pid' => getmypid(),
            'worker_id' => Cluster::getContextId(),
            'event_loop_driver' => $eventLoopDriver,
            'postgres_driver' => extension_loaded('pq') ? 'pq' : 'pgsql',
        ]);

        try {
            Cluster::awaitTermination();
            $this->logger->info('Stopping HTTP server', [
                'event' => 'http.server.stopping',
                'pid' => getmypid(),
                'worker_id' => Cluster::getContextId(),
            ]);
        } finally {
            $server->stop();
        }
    }

    public function messageBus(): MessageBusInterface
    {
        return $this->messageBus;
    }
}
