<?php

declare(strict_types=1);

namespace App\Config;

use InvalidArgumentException;

final readonly class OpenSearchConfig
{
    public function __construct(
        public bool $enabled,
        public string $url,
        public string $index,
        public bool $searchEnabled,
        public bool $coordinateUpdatesEnabled,
        public ?string $username,
        public ?string $password,
        public float $timeoutSeconds,
        public int $numberOfShards,
        public int $numberOfReplicas,
        public int $bulkNumberOfReplicas,
        public string $refreshInterval,
        public string $bulkRefreshInterval,
    ) {
        if (!$this->enabled && ($this->searchEnabled || $this->coordinateUpdatesEnabled)) {
            throw new InvalidArgumentException(
                'OPENSEARCH_ENABLED must be enabled when an OpenSearch feature is enabled.',
            );
        }
        self::assertHttpUrl($this->url);
        if (
            strlen($this->index) > 235
            || preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $this->index) !== 1
        ) {
            throw new InvalidArgumentException(
                'OPENSEARCH_INDEX must be a lowercase index alias of at most 235 bytes.',
            );
        }
        if ($this->username === null && $this->password !== null) {
            throw new InvalidArgumentException('OPENSEARCH_USERNAME is required when a password is configured.');
        }
        if ($this->timeoutSeconds <= 0) {
            throw new InvalidArgumentException('OPENSEARCH_TIMEOUT_SECONDS must be greater than zero.');
        }
        if ($this->numberOfShards < 1 || $this->numberOfShards > 64) {
            throw new InvalidArgumentException('OPENSEARCH_NUMBER_OF_SHARDS must be between 1 and 64.');
        }
        if ($this->numberOfReplicas < 0 || $this->numberOfReplicas > 32) {
            throw new InvalidArgumentException('OPENSEARCH_NUMBER_OF_REPLICAS must be between 0 and 32.');
        }
        if ($this->bulkNumberOfReplicas < 0 || $this->bulkNumberOfReplicas > 32) {
            throw new InvalidArgumentException('OPENSEARCH_BULK_NUMBER_OF_REPLICAS must be between 0 and 32.');
        }
        self::assertInterval($this->refreshInterval, 'OPENSEARCH_REFRESH_INTERVAL');
        self::assertInterval($this->bulkRefreshInterval, 'OPENSEARCH_BULK_REFRESH_INTERVAL');
    }

    public static function fromEnvironment(EnvironmentSnapshot $environment): self
    {
        return new self(
            enabled: $environment->boolean('OPENSEARCH_ENABLED', true),
            url: $environment->string('OPENSEARCH_URL', 'http://127.0.0.1:9200'),
            index: $environment->string('OPENSEARCH_INDEX', 'places'),
            searchEnabled: $environment->boolean('OPENSEARCH_SEARCH_ENABLED', false),
            coordinateUpdatesEnabled: $environment->boolean('OPENSEARCH_COORDINATE_UPDATES_ENABLED', false),
            username: $environment->optionalString('OPENSEARCH_USERNAME'),
            password: $environment->optionalString('OPENSEARCH_PASSWORD'),
            timeoutSeconds: $environment->positiveFloat('OPENSEARCH_TIMEOUT_SECONDS', 60),
            numberOfShards: self::integer($environment, 'OPENSEARCH_NUMBER_OF_SHARDS', 1),
            numberOfReplicas: self::integer($environment, 'OPENSEARCH_NUMBER_OF_REPLICAS', 0),
            bulkNumberOfReplicas: self::integer($environment, 'OPENSEARCH_BULK_NUMBER_OF_REPLICAS', 0),
            refreshInterval: $environment->string('OPENSEARCH_REFRESH_INTERVAL', '1s'),
            bulkRefreshInterval: $environment->string('OPENSEARCH_BULK_REFRESH_INTERVAL', '-1'),
        );
    }

    private static function integer(EnvironmentSnapshot $environment, string $name, int $default): int
    {
        $value = $environment->optionalString($name);
        if ($value === null) {
            return $default;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($integer)) {
            throw new InvalidArgumentException("{$name} must be an integer.");
        }

        return $integer;
    }

    private static function assertInterval(string $value, string $name): void
    {
        if ($value !== '-1' && preg_match('/^\d+(?:ms|s|m|h|d)$/D', $value) !== 1) {
            throw new InvalidArgumentException("{$name} must be -1 or a positive OpenSearch duration.");
        }
    }

    private static function assertHttpUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? $parts['scheme'] ?? null : null;
        $host = is_array($parts) ? $parts['host'] ?? null : null;

        if (!in_array($scheme, ['http', 'https'], true) || !is_string($host) || $host === '') {
            throw new InvalidArgumentException('OPENSEARCH_URL must be an absolute HTTP(S) URL.');
        }
    }
}
