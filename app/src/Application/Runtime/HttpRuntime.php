<?php

declare(strict_types=1);

namespace App\Application\Runtime;

use Amp\Cluster\Cluster;
use Amp\Sync\ChannelException;
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
        $heartbeatWatcher = null;

        try {
            $this->startupState->markComplete();
            $this->logger->info('HTTP server started', [
                'event' => 'http.server.started',
                'listen' => $this->config->listen,
                'pid' => getmypid(),
                'worker_id' => Cluster::getContextId(),
                'event_loop_driver' => $eventLoopDriver,
                'postgres_driver' => extension_loaded('pq') ? 'pq' : 'pgsql',
            ]);
            $heartbeatWatcher = $this->startHeartbeatPublisher();
            Cluster::awaitTermination();
            $this->logger->info('Stopping HTTP server', [
                'event' => 'http.server.stopping',
                'pid' => getmypid(),
                'worker_id' => Cluster::getContextId(),
            ]);
        } finally {
            if ($heartbeatWatcher !== null) {
                EventLoop::cancel($heartbeatWatcher);
            }
            $server->stop();
            $this->notifySupervisorOffline();
        }
    }

    public function messageBus(): MessageBusInterface
    {
        return $this->messageBus;
    }

    private function startHeartbeatPublisher(): ?string
    {
        $workerId = Cluster::getContextId();
        if ($workerId === null) {
            return null;
        }

        $pid = self::processId();
        Cluster::getChannel()->send(new HttpWorkerSignal(HttpWorkerSignalType::Online, $workerId, $pid));
        $watcherId = EventLoop::repeat(
            $this->config->workerHeartbeatIntervalSeconds,
            function (string $watcherId) use ($workerId, $pid): void {
                try {
                    Cluster::getChannel()->send(new HttpWorkerSignal(
                        HttpWorkerSignalType::Heartbeat,
                        $workerId,
                        $pid,
                    ));
                } catch (ChannelException $exception) {
                    EventLoop::cancel($watcherId);
                    $this->logger->warning('HTTP worker heartbeat channel is unavailable', [
                        'event' => 'http.worker.heartbeat.failed',
                        'worker_id' => $workerId,
                        'pid' => $pid,
                        'exception_class' => $exception::class,
                    ]);
                }
            },
        );
        EventLoop::unreference($watcherId);

        return $watcherId;
    }

    private function notifySupervisorOffline(): void
    {
        $workerId = Cluster::getContextId();
        if ($workerId === null) {
            return;
        }

        try {
            Cluster::getChannel()->send(new HttpWorkerSignal(
                HttpWorkerSignalType::Offline,
                $workerId,
                self::processId(),
            ));
        } catch (ChannelException) {
            // The supervisor may already have closed IPC during forced shutdown.
        }
    }

    private static function processId(): int
    {
        $pid = getmypid();
        if ($pid === false) {
            throw new RuntimeException('Unable to read the current HTTP worker process ID.');
        }

        return $pid;
    }
}
