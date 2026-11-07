<?php

declare(strict_types=1);

namespace App\Application\Runtime;

use Amp\Cluster\Cluster;
use Amp\Cluster\ClusterWatcher;
use Amp\Parallel\Ipc\LocalIpcHub;
use Amp\TimeoutCancellation;
use App\Config\HttpServerConfig;
use App\Infrastructure\Process\PipedProcessContextFactory;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Revolt\EventLoop\UnsupportedFeatureException;
use RuntimeException;

final readonly class HttpClusterRuntime
{
    public function __construct(
        private HttpServerConfig $config,
        private LoggerInterface $logger,
        private string $entrypoint,
    ) {}

    public function run(): void
    {
        if (!is_file($this->entrypoint)) {
            throw new RuntimeException(sprintf('HTTP worker entrypoint does not exist: %s', $this->entrypoint));
        }

        $hub = new LocalIpcHub();
        $watcher = new ClusterWatcher(
            [$this->entrypoint, 'server:run'],
            $this->logger,
            hub: $hub,
            contextFactory: new PipedProcessContextFactory($hub),
        );
        $signalWatchers = $this->installSignalHandlers($watcher);

        $this->logger->info('Starting HTTP worker cluster', [
            'event' => 'http.cluster.starting',
            'pid' => getmypid(),
            'workers' => $this->config->workers,
        ]);

        try {
            $watcher->start($this->config->workers);
            foreach ($watcher->getMessageIterator() as $message) {
                $this->logger->debug('Received HTTP worker message', [
                    'event' => 'http.cluster.message.received',
                    'worker_id' => $message->getWorker()->getId(),
                    'message_type' => get_debug_type($message->getData()),
                ]);
            }
        } finally {
            foreach ($signalWatchers as $watcherId) {
                EventLoop::cancel($watcherId);
            }
            $watcher->stop(new TimeoutCancellation($this->config->shutdownTimeoutSeconds));
            $this->logger->info('HTTP worker cluster stopped', [
                'event' => 'http.cluster.stopped',
                'pid' => getmypid(),
            ]);
        }
    }

    /**
     * @param ClusterWatcher<mixed, mixed> $watcher
     * @return list<string>
     */
    private function installSignalHandlers(ClusterWatcher $watcher): array
    {
        $watcherIds = [];

        try {
            foreach (Cluster::getSignalList() as $signal) {
                $watcherIds[] = EventLoop::onSignal(
                    $signal,
                    function (string $watcherId, int $signalNumber) use ($watcher): void {
                        EventLoop::cancel($watcherId);
                        $this->logger->info('Stopping HTTP worker cluster', [
                            'event' => 'http.cluster.stopping',
                            'signal' => $signalNumber,
                        ]);
                        $watcher->stop(new TimeoutCancellation($this->config->shutdownTimeoutSeconds));
                    },
                );
                EventLoop::unreference($watcherIds[array_key_last($watcherIds)]);
            }

            $restartSignal = defined('SIGUSR1') ? SIGUSR1 : 10;
            $watcherIds[] = EventLoop::onSignal(
                $restartSignal,
                function (string $watcherId, int $signalNumber) use ($watcher): void {
                    EventLoop::disable($watcherId);
                    try {
                        $this->logger->info('Restarting HTTP worker cluster', [
                            'event' => 'http.cluster.restarting',
                            'signal' => $signalNumber,
                        ]);
                        $watcher->restart();
                    } finally {
                        EventLoop::enable($watcherId);
                    }
                },
            );
            EventLoop::unreference($watcherIds[array_key_last($watcherIds)]);
        } catch (UnsupportedFeatureException) {
            foreach ($watcherIds as $watcherId) {
                EventLoop::cancel($watcherId);
            }

            $this->logger->warning('OS signal watchers are unavailable for the HTTP worker cluster', [
                'event' => 'http.cluster.signals.unavailable',
            ]);

            return [];
        }

        return $watcherIds;
    }
}
