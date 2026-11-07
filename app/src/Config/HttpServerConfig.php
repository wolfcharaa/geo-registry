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
        if ($this->connectionLimit < 1) {
            throw new InvalidArgumentException('APP_HTTP_CONNECTION_LIMIT must be greater than zero.');
        }
        if ($this->connectionsPerIpLimit < 0) {
            throw new InvalidArgumentException('APP_HTTP_CONNECTIONS_PER_IP_LIMIT must be non-negative.');
        }
        if ($this->concurrencyLimit < 0) {
            throw new InvalidArgumentException('APP_HTTP_CONCURRENCY_LIMIT must be non-negative.');
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
            connectionLimit: $environment->positiveInteger('APP_HTTP_CONNECTION_LIMIT', 512),
            connectionsPerIpLimit: $environment->nonNegativeInteger('APP_HTTP_CONNECTIONS_PER_IP_LIMIT', 64),
            concurrencyLimit: $environment->nonNegativeInteger('APP_HTTP_CONCURRENCY_LIMIT', 256),
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
