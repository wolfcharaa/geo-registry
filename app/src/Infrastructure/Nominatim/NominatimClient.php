<?php

declare(strict_types=1);

namespace App\Infrastructure\Nominatim;

use Amp\Cancellation;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use JsonException;
use RuntimeException;

final readonly class NominatimClient
{
    public function __construct(
        private HttpClient $httpClient,
        private string $baseUrl,
        private string $userAgent,
        private string $language,
    ) {}

    /** @return list<array<string, mixed>> */
    public function search(string $query, int $limit = 5, ?Cancellation $cancellation = null): array
    {
        $payload = $this->get('/search', [
            'addressdetails' => 1,
            'format' => 'jsonv2',
            'limit' => max(1, min(50, $limit)),
            'q' => $query,
        ], $cancellation);

        if (!array_is_list($payload)) {
            throw new RuntimeException('Nominatim returned an unexpected search response.');
        }

        /** @var list<array<string, mixed>> $payload */
        return $payload;
    }

    /** @return array<string, mixed> */
    public function reverse(float $latitude, float $longitude, ?Cancellation $cancellation = null): array
    {
        $payload = $this->get('/reverse', [
            'addressdetails' => 1,
            'format' => 'jsonv2',
            'lat' => $latitude,
            'lon' => $longitude,
            'zoom' => 18,
        ], $cancellation);

        if (array_is_list($payload)) {
            throw new RuntimeException('Nominatim returned an unexpected reverse response.');
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /** @return array<string, mixed> */
    public function status(?Cancellation $cancellation = null): array
    {
        $payload = $this->get('/status', ['format' => 'json'], $cancellation);

        if (array_is_list($payload) || ($payload['status'] ?? null) !== 0) {
            throw new RuntimeException('Nominatim is not ready.');
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * @param array<string, float|int|string> $query
     * @return array<array-key, mixed>
     * @throws JsonException
     */
    private function get(string $path, array $query, ?Cancellation $cancellation = null): array
    {
        $url = rtrim($this->baseUrl, '/') . $path . '?' . http_build_query($query, encoding_type: PHP_QUERY_RFC3986);
        $request = new Request($url);
        $request->setHeader('Accept', 'application/json');
        $request->setHeader('Accept-Language', $this->language);
        $request->setHeader('User-Agent', $this->userAgent);

        $response = $this->httpClient->request($request, $cancellation);
        if ($response->getStatus() < 200 || $response->getStatus() >= 300) {
            throw new RuntimeException(sprintf('Nominatim returned HTTP %d.', $response->getStatus()));
        }

        $payload = json_decode($response->getBody()->buffer(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new RuntimeException('Nominatim returned a non-object JSON response.');
        }

        return $payload;
    }
}
