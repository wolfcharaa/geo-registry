<?php

declare(strict_types=1);

namespace App\Config;

use InvalidArgumentException;

final readonly class AddressSearchCacheConfig
{
    public function __construct(
        public bool $enabled,
        public int $maxEntries,
        public int $ttlSeconds,
        public int $emptyTtlSeconds,
        public string $namespace,
    ) {
        if ($this->maxEntries < 1 || $this->maxEntries > 100_000) {
            throw new InvalidArgumentException('ADDRESS_SEARCH_CACHE_MAX_ENTRIES must be between 1 and 100000.');
        }
        if ($this->ttlSeconds < 1 || $this->ttlSeconds > 3_600) {
            throw new InvalidArgumentException('ADDRESS_SEARCH_CACHE_TTL_SECONDS must be between 1 and 3600.');
        }
        if ($this->emptyTtlSeconds < 1 || $this->emptyTtlSeconds > 300) {
            throw new InvalidArgumentException('ADDRESS_SEARCH_CACHE_EMPTY_TTL_SECONDS must be between 1 and 300.');
        }
        if ($this->emptyTtlSeconds > $this->ttlSeconds) {
            throw new InvalidArgumentException(
                'ADDRESS_SEARCH_CACHE_EMPTY_TTL_SECONDS cannot exceed ADDRESS_SEARCH_CACHE_TTL_SECONDS.',
            );
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $this->namespace) !== 1) {
            throw new InvalidArgumentException(
                'ADDRESS_SEARCH_CACHE_NAMESPACE must contain 1..64 alphanumeric, dot, underscore or dash characters.',
            );
        }
    }

    public static function fromEnvironment(EnvironmentSnapshot $environment): self
    {
        return new self(
            enabled: $environment->boolean('ADDRESS_SEARCH_CACHE_ENABLED', false),
            maxEntries: $environment->positiveInteger('ADDRESS_SEARCH_CACHE_MAX_ENTRIES', 512),
            ttlSeconds: $environment->positiveInteger('ADDRESS_SEARCH_CACHE_TTL_SECONDS', 60),
            emptyTtlSeconds: $environment->positiveInteger('ADDRESS_SEARCH_CACHE_EMPTY_TTL_SECONDS', 5),
            namespace: $environment->string('ADDRESS_SEARCH_CACHE_NAMESPACE', 'v1'),
        );
    }
}
