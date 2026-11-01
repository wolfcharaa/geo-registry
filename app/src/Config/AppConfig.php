<?php

declare(strict_types=1);

namespace App\Config;

use InvalidArgumentException;

final readonly class AppConfig
{
    public function __construct(
        public string $listen,
        public string $userAgent,
        public float $httpTimeoutSeconds,
        public string $databaseDsn,
        public int $databasePoolSize,
        public string $nominatimUrl,
        public string $nominatimLanguage,
        public string $openSearchUrl,
        public string $openSearchIndex,
        public ?string $openSearchUsername,
        public ?string $openSearchPassword,
    ) {
        if ($this->httpTimeoutSeconds <= 0) {
            throw new InvalidArgumentException('HTTP_TIMEOUT_SECONDS must be greater than zero.');
        }

        if ($this->databasePoolSize <= 0) {
            throw new InvalidArgumentException('DATABASE_POOL_SIZE must be greater than zero.');
        }

        self::assertHttpUrl($this->nominatimUrl, 'NOMINATIM_URL');
        self::assertHttpUrl($this->openSearchUrl, 'OPENSEARCH_URL');
    }

    public static function fromEnvironment(): self
    {
        return new self(
            listen: self::environment('APP_LISTEN', '127.0.0.1:8080'),
            userAgent: self::environment('APP_USER_AGENT', 'amphp-geosearch-service/0.1'),
            httpTimeoutSeconds: (float) self::environment('HTTP_TIMEOUT_SECONDS', '10'),
            databaseDsn: self::environment(
                'DATABASE_DSN',
                'host=127.0.0.1 port=5432 user=app password=local-app-only db=app application_name=amphp-service',
            ),
            databasePoolSize: self::positiveInteger('DATABASE_POOL_SIZE', 10),
            nominatimUrl: self::environment('NOMINATIM_URL', 'http://127.0.0.1:8081'),
            nominatimLanguage: self::environment('NOMINATIM_LANGUAGE', 'ru'),
            openSearchUrl: self::environment('OPENSEARCH_URL', 'http://127.0.0.1:9200'),
            openSearchIndex: self::environment('OPENSEARCH_INDEX', 'places'),
            openSearchUsername: self::optionalEnvironment('OPENSEARCH_USERNAME'),
            openSearchPassword: self::optionalEnvironment('OPENSEARCH_PASSWORD'),
        );
    }

    private static function environment(string $name, string $default): string
    {
        return self::optionalEnvironment($name) ?? $default;
    }

    private static function optionalEnvironment(string $name): ?string
    {
        $value = getenv($name);

        return $value === false || trim($value) === '' ? null : trim($value);
    }

    private static function positiveInteger(string $name, int $default): int
    {
        $value = self::optionalEnvironment($name);
        if ($value === null) {
            return $default;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($integer === false) {
            throw new InvalidArgumentException(sprintf('%s must be a positive integer.', $name));
        }

        return $integer;
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
