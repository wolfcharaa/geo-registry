<?php

declare(strict_types=1);

namespace App\Infrastructure\OpenSearch;

use Amp\Cancellation;
use Amp\Http\Client\HttpClient;
use JsonException;

final readonly class OpenSearchClient
{
    public function __construct(
        private HttpClient $httpClient,
        private OpenSearchRequestFactory $requestFactory,
    ) {}

    /** @return array<string, mixed> */
    public function health(?Cancellation $cancellation = null): array
    {
        $response = $this->request('GET', '/_cluster/health', [
            'timeout' => '2s',
            'wait_for_status' => 'yellow',
        ], cancellation: $cancellation);

        if (array_is_list($response)) {
            throw new OpenSearchException('OpenSearch returned an unexpected health response.');
        }

        /** @var array<string, mixed> $response */
        return $response;
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function search(
        string $index,
        array $query,
        int $size = 10,
        ?Cancellation $cancellation = null,
    ): array {
        $response = $this->request(
            'POST',
            '/' . rawurlencode($index) . '/_search',
            body: [
                'query' => $query,
                'size' => max(1, min(100, $size)),
            ],
            cancellation: $cancellation,
        );

        if (array_is_list($response)) {
            throw new OpenSearchException('OpenSearch returned an unexpected search response.');
        }

        /** @var array<string, mixed> $response */
        return $response;
    }

    /**
     * @param array<string, bool|float|int|string|null> $query
     * @param array<array-key, mixed>|string|null $body
     * @return array<array-key, mixed>
     */
    public function request(
        string $method,
        string $path,
        array $query = [],
        array|string|null $body = null,
        string $contentType = 'application/json',
        ?Cancellation $cancellation = null,
    ): array {
        $request = $this->requestFactory->create($method, $path, $query, $body, $contentType);
        $response = $this->httpClient->request($request, $cancellation);
        $rawBody = $response->getBody()->buffer();

        try {
            $payload = $rawBody === '' ? [] : json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new OpenSearchException(
                'OpenSearch returned invalid JSON.',
                $response->getStatus(),
                $rawBody,
                $exception,
            );
        }

        if ($response->getStatus() < 200 || $response->getStatus() >= 300) {
            throw new OpenSearchException(
                sprintf('OpenSearch returned HTTP %d.', $response->getStatus()),
                $response->getStatus(),
                is_array($payload) ? $payload : $rawBody,
            );
        }

        if (!is_array($payload)) {
            throw new OpenSearchException(
                'OpenSearch returned an unexpected JSON value.',
                $response->getStatus(),
                $rawBody,
            );
        }

        return $payload;
    }
}
