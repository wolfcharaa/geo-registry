<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\Http;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\TimeoutCancellation;
use App\Module\AddressRegistry\Capability\Releases\GarReleaseMetadata;
use App\Module\AddressRegistry\Capability\Releases\GarReleaseMetadataReader;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;
use Throwable;

final readonly class HttpGarReleaseMetadataReader implements GarReleaseMetadataReader
{
    private const string BASE_URL = 'https://fias-file.nalog.ru/WebServices/Public';
    private const int MAX_RESPONSE_BYTES = 4_194_304;

    public function __construct(
        private HttpClient $httpClient,
        private string $userAgent,
    ) {}

    public function endpoint(bool $all): string
    {
        return self::BASE_URL . '/' . ($all ? 'GetAllDownloadFileInfo' : 'GetLastDownloadFileInfo');
    }

    public function latest(?string $url, int $timeoutSeconds): GarReleaseMetadata
    {
        $payload = $this->unwrap(
            $this->request($url ?? $this->endpoint(false), $timeoutSeconds),
            'GetLastDownloadFileInfoResult',
        );
        if (!is_array($payload)) {
            throw new RuntimeException('GAR latest metadata response must be a JSON object.');
        }
        if (!$this->looksLikeRelease($payload)) {
            $items = array_values(array_filter($payload, 'is_array'));
            $payload = $items[0] ?? null;
            if (!is_array($payload)) {
                throw new RuntimeException('GAR latest metadata response contains no release.');
            }
        }

        return $this->map($payload);
    }

    public function all(?string $url, int $timeoutSeconds): array
    {
        $payload = $this->unwrap(
            $this->request($url ?? $this->endpoint(true), $timeoutSeconds),
            'GetAllDownloadFileInfoResult',
        );
        if (!is_array($payload)) {
            throw new RuntimeException('GAR metadata response must be a JSON object or list.');
        }

        $items = $this->looksLikeRelease($payload) ? [$payload] : $payload;
        $releases = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $releases[] = $this->map($item);
            }
        }
        usort(
            $releases,
            static fn(GarReleaseMetadata $left, GarReleaseMetadata $right): int =>
                $right->versionId <=> $left->versionId,
        );

        return $releases;
    }

    /** @return array<array-key, mixed>|bool|float|int|string|null */
    private function request(string $url, int $timeoutSeconds): array|bool|float|int|string|null
    {
        self::assertUrl($url);
        if ($timeoutSeconds < 1 || $timeoutSeconds > 60) {
            throw new RuntimeException('GAR metadata timeout must be between 1 and 60 seconds.');
        }

        $request = new Request($url);
        $request->setHeader('Accept', 'application/json');
        $request->setHeader('User-Agent', $this->userAgent);
        $request->setBodySizeLimit(self::MAX_RESPONSE_BYTES);

        try {
            $response = $this->httpClient->request($request, new TimeoutCancellation($timeoutSeconds));
            $body = $response->getBody()->buffer();
        } catch (Throwable $error) {
            throw new RuntimeException('GAR metadata request failed.', previous: $error);
        }
        if ($response->getStatus() < 200 || $response->getStatus() >= 300) {
            throw new RuntimeException(sprintf(
                'GAR metadata endpoint returned HTTP %d.',
                $response->getStatus(),
            ));
        }

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('GAR metadata response is not valid JSON.', previous: $error);
        }
        if ($payload !== null && !is_array($payload) && !is_scalar($payload)) {
            throw new RuntimeException('GAR metadata response contains an unsupported JSON value.');
        }

        return $payload;
    }

    private function unwrap(mixed $payload, string $field): mixed
    {
        return is_array($payload) && array_key_exists($field, $payload)
            ? $payload[$field]
            : $payload;
    }

    /** @param array<array-key, mixed> $item */
    private function map(array $item): GarReleaseMetadata
    {
        $version = self::scalar($item, 'VersionId', 'versionId');
        if ($version === null || !ctype_digit($version)) {
            throw new RuntimeException('GAR metadata release has no numeric VersionId.');
        }

        $fullUrl = self::scalar(
            $item,
            'GarXMLFullURL',
            'garXMLFullUrl',
            'GARXMLFullURL',
            'FiasCompleteXmlUrl',
            'fiasCompleteXmlUrl',
        );
        $deltaUrl = self::scalar(
            $item,
            'GarXMLDeltaURL',
            'garXMLDeltaUrl',
            'GARXMLDeltaURL',
            'FiasDeltaXmlUrl',
            'fiasDeltaXmlUrl',
        );
        $textVersion = self::scalar($item, 'TextVersion', 'textVersion');

        return new GarReleaseMetadata(
            versionId: (int) $version,
            textVersion: $textVersion,
            date: self::date(self::scalar($item, 'Date', 'date') ?? $textVersion, $fullUrl, $deltaUrl),
            fullXmlUrl: $fullUrl,
            deltaXmlUrl: $deltaUrl,
        );
    }

    /** @param array<array-key, mixed> $item */
    private function looksLikeRelease(array $item): bool
    {
        return self::scalar($item, 'VersionId', 'versionId') !== null;
    }

    /** @param array<array-key, mixed> $item */
    private static function scalar(array $item, string ...$fields): ?string
    {
        foreach ($fields as $field) {
            $value = $item[$field] ?? null;
            if (!is_scalar($value)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private static function date(?string $value, ?string ...$urls): ?string
    {
        if ($value !== null) {
            if (preg_match('/^\/Date\((\d+)(?:[+-]\d+)?\)\/$/D', trim($value), $matches) === 1) {
                return (new DateTimeImmutable('@' . intdiv((int) $matches[1], 1000)))
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format('Y-m-d');
            }
            if (preg_match('/\b(\d{4})[.-](\d{2})[.-](\d{2})\b/', $value, $matches) === 1) {
                return "{$matches[1]}-{$matches[2]}-{$matches[3]}";
            }
            if (preg_match('/\b(\d{2})[.-](\d{2})[.-](\d{4})\b/', $value, $matches) === 1) {
                return "{$matches[3]}-{$matches[2]}-{$matches[1]}";
            }
        }

        foreach ($urls as $url) {
            if ($url !== null && preg_match('/(\d{4})[._-](\d{2})[._-](\d{2})/', $url, $matches) === 1) {
                return "{$matches[1]}-{$matches[2]}-{$matches[3]}";
            }
        }

        return null;
    }

    private static function assertUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? $parts['scheme'] ?? null : null;
        $host = is_array($parts) ? $parts['host'] ?? null : null;
        if (!in_array($scheme, ['http', 'https'], true) || !is_string($host) || $host === '') {
            throw new RuntimeException('GAR metadata URL must be an absolute HTTP(S) URL.');
        }
    }
}
