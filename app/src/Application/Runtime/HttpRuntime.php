<?php

declare(strict_types=1);

namespace App\Application\Runtime;

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\SocketHttpServer;

use function Amp\trapSignal;

use App\Application\Health\StartupState;
use App\Config\HttpServerConfig;
use App\UserInterface\Http\RouterFactory;
use Fedor\MessageBus\MessageBusInterface;
use Psr\Log\LoggerInterface;

final readonly class HttpRuntime
{
    public function __construct(
        private HttpServerConfig $config,
        private LoggerInterface $logger,
        private RouterFactory $routerFactory,
        private StartupState $startupState,
        private MessageBusInterface $messageBus,
    ) {}

    public function run(): void
    {
        $errorHandler = new DefaultErrorHandler();
        $server = SocketHttpServer::createForDirectAccess($this->logger);
        $router = $this->routerFactory->create($server, $errorHandler);

        $server->expose($this->config->listen);
        $server->start($router, $errorHandler);
        $this->startupState->markComplete();
        $this->logger->info('HTTP server started', [
            'event' => 'http.server.started',
            'listen' => $this->config->listen,
        ]);

        try {
            $signal = trapSignal([SIGINT, SIGTERM]);
            $this->logger->info('Stopping HTTP server', [
                'event' => 'http.server.stopping',
                'signal' => $signal,
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
