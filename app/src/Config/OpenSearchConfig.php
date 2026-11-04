<?php

declare(strict_types=1);

namespace App\Config;

use InvalidArgumentException;

final readonly class OpenSearchConfig
{
    public function __construct(
        public string $url,
        public string $index,
        public bool $searchEnabled,
        public ?string $username,
        public ?string $password,
    ) {
        self::assertHttpUrl($this->url);
        if (trim($this->index) === '') {
            throw new InvalidArgumentException('OPENSEARCH_INDEX must be a non-empty string.');
        }
        if ($this->username === null && $this->password !== null) {
            throw new InvalidArgumentException('OPENSEARCH_USERNAME is required when a password is configured.');
        }
    }

    public static function fromEnvironment(EnvironmentSnapshot $environment): self
    {
        return new self(
            url: $environment->string('OPENSEARCH_URL', 'http://127.0.0.1:9200'),
            index: $environment->string('OPENSEARCH_INDEX', 'places'),
            searchEnabled: $environment->boolean('OPENSEARCH_SEARCH_ENABLED', false),
            username: $environment->optionalString('OPENSEARCH_USERNAME'),
            password: $environment->optionalString('OPENSEARCH_PASSWORD'),
        );
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
