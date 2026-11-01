<?php

declare(strict_types=1);

namespace App\Infrastructure\OpenSearch;

use Amp\Http\Client\HttpClient;

final readonly class OpenSearchClientFactory
{
    public function __construct(private HttpClient $httpClient) {}

    public function create(
        string $baseUrl,
        string $userAgent,
        ?string $username = null,
        ?string $password = null,
    ): OpenSearchClient {
        return new OpenSearchClient(
            $this->httpClient,
            new OpenSearchRequestFactory($baseUrl, $userAgent, $username, $password),
        );
    }
}
