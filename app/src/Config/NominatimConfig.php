<?php

declare(strict_types=1);

namespace App\Config;

use InvalidArgumentException;

final readonly class NominatimConfig
{
    public function __construct(
        public bool $enabled,
        public string $url,
        public string $language,
    ) {
        self::assertHttpUrl($this->url, 'NOMINATIM_URL');
        if (trim($this->language) === '') {
            throw new InvalidArgumentException('NOMINATIM_LANGUAGE must be a non-empty string.');
        }
    }

    public static function fromEnvironment(EnvironmentSnapshot $environment): self
    {
        return new self(
            enabled: $environment->boolean('NOMINATIM_ENABLED', true),
            url: $environment->string('NOMINATIM_URL', 'http://127.0.0.1:8081'),
            language: $environment->string('NOMINATIM_LANGUAGE', 'ru'),
        );
    }

    private static function assertHttpUrl(string $url, string $name): void
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? $parts['scheme'] ?? null : null;
        $host = is_array($parts) ? $parts['host'] ?? null : null;

        if (!in_array($scheme, ['http', 'https'], true) || !is_string($host) || $host === '') {
            throw new InvalidArgumentException(sprintf('%s must be an absolute HTTP(S) URL.', $name));
        }
    }
}
