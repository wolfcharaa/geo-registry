<?php

declare(strict_types=1);

namespace App\Application\Runtime;

use Amp\Cluster\Cluster;
use Amp\Cluster\ClusterWatcher;
use Amp\DeferredCancellation;

use function Amp\now;

use Amp\Parallel\Ipc\LocalIpcHub;
use Amp\TimeoutCancellation;
use App\Config\HttpServerConfig;
use App\Infrastructure\Process\PipedProcessContextFactory;
use App\Infrastructure\Process\ProcessRestartBackoff;
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
        $workerStartCancellation = new DeferredCancellation();
        $workerPool = new HttpWorkerPoolState(
            expectedWorkers: $this->config->workers,
            heartbeatTimeoutSeconds: $this->config->workerHeartbeatTimeoutSeconds,
        );
        $restartBackoff = new ProcessRestartBackoff(
            initialStartCount: $this->config->workers,
            restartBurst: $this->config->workerRestartBurst,
            windowSeconds: $this->config->workerRestartWindowSeconds,
            initialDelaySeconds: $this->config->workerRestartBackoffInitialSeconds,
            maximumDelaySeconds: $this->config->workerRestartBackoffMaximumSeconds,
        );
        $staleWorkerTerminator = new HttpStaleWorkerTerminator(
            $this->config->workerStaleTerminationGraceSeconds,
            $this->logger,
        );
        $watcher = new ClusterWatcher(
            [$this->entrypoint, 'server:run'],
            $this->logger,
            hub: $hub,
            contextFactory: new PipedProcessContextFactory(
                $hub,
                $restartBackoff,
                $this->logger,
                $workerStartCancellation->getCancellation(),
            ),
        );
        $signalWatchers = $this->installSignalHandlers($watcher, $workerStartCancellation);

        $this->logger->info('Starting HTTP worker cluster', [
            'event' => 'http.cluster.starting',
            'pid' => getmypid(),
            'workers' => $this->config->workers,
            'restart_burst' => $this->config->workerRestartBurst,
            'restart_window_seconds' => $this->config->workerRestartWindowSeconds,
            'restart_backoff_initial_seconds' => $this->config->workerRestartBackoffInitialSeconds,
            'restart_backoff_maximum_seconds' => $this->config->workerRestartBackoffMaximumSeconds,
            'worker_heartbeat_interval_seconds' => $this->config->workerHeartbeatIntervalSeconds,
            'worker_heartbeat_timeout_seconds' => $this->config->workerHeartbeatTimeoutSeconds,
            'worker_stale_termination_grace_seconds' => $this->config->workerStaleTerminationGraceSeconds,
        ]);

        $capacityMonitorWatcher = null;
        try {
            $watcher->start($this->config->workers);
            $capacityMonitorWatcher = EventLoop::repeat(
                $this->config->workerHeartbeatIntervalSeconds,
                function (string $watcherId) use (
                    $workerPool,
                    $workerStartCancellation,
                    $staleWorkerTerminator,
                ): void {
                    if ($workerStartCancellation->isCancelled()) {
                        EventLoop::cancel($watcherId);

                        return;
                    }

                    $this->observeWorkerPool($workerPool, $staleWorkerTerminator, now());
                },
            );
            EventLoop::unreference($capacityMonitorWatcher);
            foreach ($watcher->getMessageIterator() as $message) {
                $data = $message->getData();
                if ($data instanceof HttpWorkerSignal && !$workerStartCancellation->isCancelled()) {
                    $this->handleWorkerSignal($workerPool, $staleWorkerTerminator, $data);

                    continue;
                }

                $this->logger->debug('Received HTTP worker message', [
                    'event' => 'http.cluster.message.received',
                    'worker_id' => $message->getWorker()->getId(),
                    'message_type' => get_debug_type($data),
                ]);
            }
        } finally {
            $workerStartCancellation->cancel();
            $staleWorkerTerminator->stop();
            if ($capacityMonitorWatcher !== null) {
                EventLoop::cancel($capacityMonitorWatcher);
            }
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

    private function handleWorkerSignal(
        HttpWorkerPoolState $workerPool,
        HttpStaleWorkerTerminator $staleWorkerTerminator,
        HttpWorkerSignal $signal,
    ): void {
        $now = now();
        $workerPool->observe($signal, $now);

        if ($signal->type === HttpWorkerSignalType::Online) {
            $this->logger->info('HTTP worker is online', [
                'event' => 'http.cluster.worker.online',
                'worker_id' => $signal->workerId,
                'pid' => $signal->pid,
                'active_workers' => $workerPool->activeWorkers(),
                'expected_workers' => $this->config->workers,
            ]);
        } elseif ($signal->type === HttpWorkerSignalType::Offline) {
            $this->logger->info('HTTP worker is offline', [
                'event' => 'http.cluster.worker.offline',
                'worker_id' => $signal->workerId,
                'pid' => $signal->pid,
                'active_workers' => $workerPool->activeWorkers(),
                'expected_workers' => $this->config->workers,
            ]);

            return;
        }

        $this->observeWorkerPool($workerPool, $staleWorkerTerminator, $now);
    }

    private function observeWorkerPool(
        HttpWorkerPoolState $workerPool,
        HttpStaleWorkerTerminator $staleWorkerTerminator,
        float $now,
    ): void {
        $observation = $workerPool->inspect($now, self::isProcessAlive(...));
        foreach ($observation->staleWorkers as $worker) {
            $staleWorkerTerminator->terminate($worker);
        }
        $this->logWorkerPoolObservation($observation);
    }

    private function logWorkerPoolObservation(HttpWorkerPoolObservation $observation): void
    {
        $context = [
            'active_workers' => $observation->activeWorkers,
            'expected_workers' => $observation->expectedWorkers,
            'removed_worker_ids' => $observation->removedWorkerIds,
        ];

        match ($observation->transition) {
            HttpWorkerPoolTransition::Ready => $this->logger->info(
                'HTTP worker pool reached the configured capacity',
                ['event' => 'http.cluster.capacity.ready', ...$context],
            ),
            HttpWorkerPoolTransition::Degraded => $this->logger->warning(
                'HTTP worker pool is below the configured capacity',
                ['event' => 'http.cluster.capacity.degraded', ...$context],
            ),
            HttpWorkerPoolTransition::Restored => $this->logger->info(
                'HTTP worker pool capacity was restored',
                ['event' => 'http.cluster.capacity.restored', ...$context],
            ),
            null => null,
        };
    }

    private static function isProcessAlive(int $pid): bool
    {
        return posix_kill($pid, 0);
    }

    /**
     * @param ClusterWatcher<mixed, mixed> $watcher
     * @return list<string>
     */
    private function installSignalHandlers(
        ClusterWatcher $watcher,
        DeferredCancellation $workerStartCancellation,
    ): array {
        $watcherIds = [];

        try {
            foreach (Cluster::getSignalList() as $signal) {
                $watcherIds[] = EventLoop::onSignal(
                    $signal,
                    function (string $watcherId, int $signalNumber) use (
                        $watcher,
                        $workerStartCancellation,
                    ): void {
                        EventLoop::cancel($watcherId);
                        $this->logger->info('Stopping HTTP worker cluster', [
                            'event' => 'http.cluster.stopping',
                            'signal' => $signalNumber,
                        ]);
                        $workerStartCancellation->cancel();
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
