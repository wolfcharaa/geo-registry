<?php

declare(strict_types=1);

namespace App\Config;

use InvalidArgumentException;

final readonly class HttpServerConfig
{
    private const BYTES_PER_MEBIBYTE = 1024 * 1024;

    public function __construct(
        public string $listen,
        public string $userAgent,
        public float $timeoutSeconds,
        public int $workers,
        public float $shutdownTimeoutSeconds,
        public int $workerMaxRequests,
        public int $workerMaxMemoryMegabytes,
        public int $workerRestartBurst,
        public float $workerRestartWindowSeconds,
        public float $workerRestartBackoffInitialSeconds,
        public float $workerRestartBackoffMaximumSeconds,
        public float $workerHeartbeatIntervalSeconds,
        public float $workerHeartbeatTimeoutSeconds,
        public int $connectionLimit,
        public int $connectionsPerIpLimit,
        public int $concurrencyLimit,
        public int $backlog,
        public bool $compressionEnabled,
    ) {
        if (trim($this->listen) === '') {
            throw new InvalidArgumentException('APP_LISTEN must be a non-empty string.');
        }
        if (trim($this->userAgent) === '') {
            throw new InvalidArgumentException('APP_USER_AGENT must be a non-empty string.');
        }
        if ($this->timeoutSeconds <= 0) {
            throw new InvalidArgumentException('HTTP_TIMEOUT_SECONDS must be greater than zero.');
        }
        if ($this->workers < 1 || $this->workers > 64) {
            throw new InvalidArgumentException('APP_HTTP_WORKERS must be between 1 and 64.');
        }
        if ($this->shutdownTimeoutSeconds <= 0) {
            throw new InvalidArgumentException('APP_HTTP_SHUTDOWN_TIMEOUT_SECONDS must be greater than zero.');
        }
        if ($this->workerMaxRequests < 0) {
            throw new InvalidArgumentException('APP_HTTP_WORKER_MAX_REQUESTS must be non-negative.');
        }
        if ($this->workerMaxMemoryMegabytes < 0) {
            throw new InvalidArgumentException('APP_HTTP_WORKER_MAX_MEMORY_MB must be non-negative.');
        }
        if ($this->workerMaxMemoryMegabytes > intdiv(PHP_INT_MAX, self::BYTES_PER_MEBIBYTE)) {
            throw new InvalidArgumentException('APP_HTTP_WORKER_MAX_MEMORY_MB is too large.');
        }
        if ($this->workerRestartBurst < 0 || $this->workerRestartBurst > 1000) {
            throw new InvalidArgumentException('APP_HTTP_WORKER_RESTART_BURST must be between 0 and 1000.');
        }
        if ($this->workerRestartWindowSeconds <= 0 || $this->workerRestartWindowSeconds > 3600) {
            throw new InvalidArgumentException('APP_HTTP_WORKER_RESTART_WINDOW_SECONDS must be greater than zero and at most 3600.');
        }
        if ($this->workerRestartBackoffInitialSeconds <= 0 || $this->workerRestartBackoffInitialSeconds > 300) {
            throw new InvalidArgumentException('APP_HTTP_WORKER_RESTART_BACKOFF_INITIAL_SECONDS must be greater than zero and at most 300.');
        }
        if ($this->workerRestartBackoffMaximumSeconds < $this->workerRestartBackoffInitialSeconds
            || $this->workerRestartBackoffMaximumSeconds > 300
        ) {
            throw new InvalidArgumentException(
                'APP_HTTP_WORKER_RESTART_BACKOFF_MAX_SECONDS must be at least the initial delay and at most 300.',
            );
        }
        if ($this->workerHeartbeatIntervalSeconds <= 0 || $this->workerHeartbeatIntervalSeconds > 300) {
            throw new InvalidArgumentException(
                'APP_HTTP_WORKER_HEARTBEAT_INTERVAL_SECONDS must be greater than zero and at most 300.',
            );
        }
        if ($this->workerHeartbeatTimeoutSeconds < ($this->workerHeartbeatIntervalSeconds * 2)
            || $this->workerHeartbeatTimeoutSeconds > 900
        ) {
            throw new InvalidArgumentException(
                'APP_HTTP_WORKER_HEARTBEAT_TIMEOUT_SECONDS must be at least two intervals and at most 900.',
            );
        }
        if ($this->connectionLimit < 1) {
            throw new InvalidArgumentException('APP_HTTP_CONNECTION_LIMIT must be greater than zero.');
        }
        if ($this->connectionsPerIpLimit < 0) {
            throw new InvalidArgumentException('APP_HTTP_CONNECTIONS_PER_IP_LIMIT must be non-negative.');
        }
        if ($this->concurrencyLimit < 1) {
            throw new InvalidArgumentException('APP_HTTP_CONCURRENCY_LIMIT must be greater than zero.');
        }
        if ($this->backlog < 1) {
            throw new InvalidArgumentException('APP_HTTP_BACKLOG must be greater than zero.');
        }
    }

    public static function fromEnvironment(EnvironmentSnapshot $environment): self
    {
        return new self(
            listen: $environment->string('APP_LISTEN', '127.0.0.1:8080'),
            userAgent: $environment->string('APP_USER_AGENT', 'geo-registry/0.1'),
            timeoutSeconds: $environment->positiveFloat('HTTP_TIMEOUT_SECONDS', 10),
            workers: $environment->positiveInteger('APP_HTTP_WORKERS', 1),
            shutdownTimeoutSeconds: $environment->positiveFloat('APP_HTTP_SHUTDOWN_TIMEOUT_SECONDS', 30),
            workerMaxRequests: $environment->nonNegativeInteger('APP_HTTP_WORKER_MAX_REQUESTS', 0),
            workerMaxMemoryMegabytes: $environment->nonNegativeInteger('APP_HTTP_WORKER_MAX_MEMORY_MB', 0),
            workerRestartBurst: $environment->nonNegativeInteger('APP_HTTP_WORKER_RESTART_BURST', 5),
            workerRestartWindowSeconds: $environment->positiveFloat('APP_HTTP_WORKER_RESTART_WINDOW_SECONDS', 30),
            workerRestartBackoffInitialSeconds: $environment->positiveFloat(
                'APP_HTTP_WORKER_RESTART_BACKOFF_INITIAL_SECONDS',
                0.5,
            ),
            workerRestartBackoffMaximumSeconds: $environment->positiveFloat(
                'APP_HTTP_WORKER_RESTART_BACKOFF_MAX_SECONDS',
                30,
            ),
            workerHeartbeatIntervalSeconds: $environment->positiveFloat(
                'APP_HTTP_WORKER_HEARTBEAT_INTERVAL_SECONDS',
                5,
            ),
            workerHeartbeatTimeoutSeconds: $environment->positiveFloat(
                'APP_HTTP_WORKER_HEARTBEAT_TIMEOUT_SECONDS',
                15,
            ),
            connectionLimit: $environment->positiveInteger('APP_HTTP_CONNECTION_LIMIT', 512),
            connectionsPerIpLimit: $environment->nonNegativeInteger('APP_HTTP_CONNECTIONS_PER_IP_LIMIT', 64),
            concurrencyLimit: $environment->positiveInteger('APP_HTTP_CONCURRENCY_LIMIT', 256),
            backlog: $environment->positiveInteger('APP_HTTP_BACKLOG', 512),
            compressionEnabled: $environment->boolean('APP_HTTP_COMPRESSION_ENABLED', true),
        );
    }

    /** @return positive-int */
    public function connectionLimit(): int
    {
        return $this->connectionLimit;
    }

    /** @return positive-int */
    public function backlog(): int
    {
        return $this->backlog;
    }

    /** @return non-negative-int */
    public function workerMaxMemoryBytes(): int
    {
        return $this->workerMaxMemoryMegabytes * self::BYTES_PER_MEBIBYTE;
    }
}
