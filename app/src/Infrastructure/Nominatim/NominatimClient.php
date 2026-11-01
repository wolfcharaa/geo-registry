<?php

declare(strict_types=1);

namespace App\Infrastructure\Nominatim;

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
    public function search(string $query, int $limit = 5): array
    {
        $payload = $this->get('/search', [
            'addressdetails' => 1,
            'format' => 'jsonv2',
            'limit' => max(1, min(50, $limit)),
            'q' => $query,
        ]);

        if (!array_is_list($payload)) {
            throw new RuntimeException('Nominatim returned an unexpected search response.');
        }

        /** @var list<array<string, mixed>> $payload */
        return $payload;
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $payload = $this->get('/status', ['format' => 'json']);

        if (array_is_list($payload) || ($payload['status'] ?? null) !== 0) {
            throw new RuntimeException('Nominatim is not ready.');
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * @param array<string, int|string> $query
     * @return array<array-key, mixed>
     * @throws JsonException
     */
    private function get(string $path, array $query): array
    {
        $url = rtrim($this->baseUrl, '/') . $path . '?' . http_build_query($query, encoding_type: PHP_QUERY_RFC3986);
        $request = new Request($url);
        $request->setHeader('Accept', 'application/json');
        $request->setHeader('Accept-Language', $this->language);
        $request->setHeader('User-Agent', $this->userAgent);

        $response = $this->httpClient->request($request);
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
