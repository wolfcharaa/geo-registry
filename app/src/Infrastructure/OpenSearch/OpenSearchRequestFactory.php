<?php

declare(strict_types=1);

namespace App\Infrastructure\OpenSearch;

use Amp\Http\Client\Request;
use InvalidArgumentException;

final readonly class OpenSearchRequestFactory
{
    public function __construct(
        private string $baseUrl,
        private string $userAgent,
        private ?string $username = null,
        private ?string $password = null,
    ) {
        $parts = parse_url($this->baseUrl);
        $scheme = is_array($parts) ? $parts['scheme'] ?? null : null;
        $host = is_array($parts) ? $parts['host'] ?? null : null;
        if (!in_array($scheme, ['http', 'https'], true) || !is_string($host) || $host === '') {
            throw new InvalidArgumentException('OpenSearch base URL must be an absolute HTTP(S) URL.');
        }

        if ($this->username === null && $this->password !== null) {
            throw new InvalidArgumentException('OpenSearch username is required when a password is configured.');
        }
    }

    /**
     * @param array<string, bool|float|int|string|null> $query
     * @param array<array-key, mixed>|string|null $body
     */
    public function create(
        string $method,
        string $path,
        array $query = [],
        array|string|null $body = null,
        string $contentType = 'application/json',
    ): Request {
        $method = strtoupper(trim($method));
        if ($method === '' || preg_match('/^[A-Z]+$/D', $method) !== 1) {
            throw new InvalidArgumentException('OpenSearch HTTP method must contain only ASCII letters.');
        }

        if (
            !str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || str_contains($path, '://')
            || str_contains($path, '?')
        ) {
            throw new InvalidArgumentException('OpenSearch path must be an absolute path without a query string.');
        }

        $url = rtrim($this->baseUrl, '/') . $path;
        $queryString = self::buildQueryString($query);
        if ($queryString !== '') {
            $url .= '?' . $queryString;
        }

        $request = new Request($url, $method);
        $request->setHeader('Accept', 'application/json');
        $request->setHeader('User-Agent', $this->userAgent);

        if ($this->username !== null) {
            $request->setHeader('Authorization', 'Basic ' . base64_encode($this->username . ':' . ($this->password ?? '')));
        }

        if ($body !== null) {
            $request->setHeader('Content-Type', $contentType);
            $request->setBody(is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body);
        }

        return $request;
    }

    /** @param array<string, bool|float|int|string|null> $query */
    private static function buildQueryString(array $query): string
    {
        $normalized = [];
        foreach ($query as $name => $value) {
            if ($value === null) {
                continue;
            }

            $normalized[$name] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return http_build_query($normalized, encoding_type: PHP_QUERY_RFC3986);
    }
}
