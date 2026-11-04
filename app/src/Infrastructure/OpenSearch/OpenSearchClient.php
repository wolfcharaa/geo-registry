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
     * @param list<array<string, mixed>|string> $sort
     * @return array<string, mixed>
     */
    public function search(
        string $index,
        array $query,
        int $size = 10,
        ?Cancellation $cancellation = null,
        array $sort = [],
    ): array {
        $body = [
            'query' => $query,
            'size' => max(1, min(100, $size)),
        ];
        if ($sort !== []) {
            $body['sort'] = $sort;
        }
        $response = $this->request(
            'POST',
            '/' . rawurlencode($index) . '/_search',
            body: $body,
            cancellation: $cancellation,
        );

        if (array_is_list($response)) {
            throw new OpenSearchException('OpenSearch returned an unexpected search response.');
        }

        /** @var array<string, mixed> $response */
        return $response;
    }

    /** @param array<string, mixed> $definition */
    public function createIndex(string $index, array $definition, ?Cancellation $cancellation = null): void
    {
        $response = $this->request(
            'PUT',
            '/' . rawurlencode($index),
            body: $definition,
            cancellation: $cancellation,
        );
        if (($response['acknowledged'] ?? null) !== true) {
            throw new OpenSearchException('OpenSearch did not acknowledge index creation.');
        }
    }

    public function deleteIndex(string $index, ?Cancellation $cancellation = null): void
    {
        $response = $this->request(
            'DELETE',
            '/' . rawurlencode($index),
            cancellation: $cancellation,
        );
        if (($response['acknowledged'] ?? null) !== true) {
            throw new OpenSearchException('OpenSearch did not acknowledge index deletion.');
        }
    }

    public function indexExists(string $index, ?Cancellation $cancellation = null): bool
    {
        try {
            $this->request('GET', '/' . rawurlencode($index), cancellation: $cancellation);

            return true;
        } catch (OpenSearchException $exception) {
            if ($exception->status === 404) {
                return false;
            }

            throw $exception;
        }
    }

    public function concreteIndexExists(string $index, ?Cancellation $cancellation = null): bool
    {
        try {
            $response = $this->request(
                'GET',
                '/_cat/indices/' . rawurlencode($index),
                ['format' => 'json'],
                cancellation: $cancellation,
            );
        } catch (OpenSearchException $exception) {
            if ($exception->status === 404) {
                return false;
            }

            throw $exception;
        }

        foreach ($response as $row) {
            if (is_array($row) && ($row['index'] ?? null) === $index) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $document */
    public function indexDocument(
        string $index,
        string $id,
        array $document,
        ?Cancellation $cancellation = null,
    ): void {
        $response = $this->request(
            'PUT',
            '/' . rawurlencode($index) . '/_doc/' . rawurlencode($id),
            body: $document,
            cancellation: $cancellation,
        );
        if (!in_array($response['result'] ?? null, ['created', 'updated'], true)) {
            throw new OpenSearchException('OpenSearch did not index the document.');
        }
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $script
     */
    public function updateByQuery(
        string $index,
        array $query,
        array $script,
        bool $refresh = false,
        ?Cancellation $cancellation = null,
    ): int {
        $response = $this->request(
            'POST',
            '/' . rawurlencode($index) . '/_update_by_query',
            ['conflicts' => 'proceed', 'refresh' => $refresh],
            ['query' => $query, 'script' => $script],
            cancellation: $cancellation,
        );
        $updated = $response['updated'] ?? null;
        $versionConflicts = $response['version_conflicts'] ?? null;
        $failures = $response['failures'] ?? null;
        if (
            !is_int($updated)
            || $updated < 0
            || $versionConflicts !== 0
            || !is_array($failures)
            || $failures !== []
            || ($response['timed_out'] ?? null) !== false
        ) {
            throw new OpenSearchException('OpenSearch did not complete the update-by-query operation.');
        }

        return $updated;
    }

    public function refreshIndex(string $index, ?Cancellation $cancellation = null): void
    {
        $this->request(
            'POST',
            '/' . rawurlencode($index) . '/_refresh',
            cancellation: $cancellation,
        );
    }

    /** @param array<string, int|string> $settings */
    public function updateIndexSettings(
        string $index,
        array $settings,
        ?Cancellation $cancellation = null,
    ): void {
        $response = $this->request(
            'PUT',
            '/' . rawurlencode($index) . '/_settings',
            body: ['index' => $settings],
            cancellation: $cancellation,
        );
        if (($response['acknowledged'] ?? null) !== true) {
            throw new OpenSearchException('OpenSearch did not acknowledge index settings update.');
        }
    }

    public function countDocuments(string $index, ?Cancellation $cancellation = null): int
    {
        $response = $this->request(
            'GET',
            '/' . rawurlencode($index) . '/_count',
            cancellation: $cancellation,
        );
        $count = $response['count'] ?? null;
        if (!is_int($count) || $count < 0) {
            throw new OpenSearchException('OpenSearch returned an invalid document count.');
        }

        return $count;
    }

    public function bulkIndex(
        string $index,
        string $ndjson,
        int $expectedDocuments,
        ?Cancellation $cancellation = null,
    ): int {
        if ($expectedDocuments <= 0 || $ndjson === '' || !str_ends_with($ndjson, "\n")) {
            throw new OpenSearchException('OpenSearch bulk request must contain newline-terminated documents.');
        }

        $response = $this->request(
            'POST',
            '/' . rawurlencode($index) . '/_bulk',
            body: $ndjson,
            contentType: 'application/x-ndjson',
            cancellation: $cancellation,
        );
        $items = $response['items'] ?? null;
        if (!is_array($items) || !array_is_list($items) || count($items) !== $expectedDocuments) {
            throw new OpenSearchException('OpenSearch bulk response contains an unexpected item count.');
        }

        $failures = [];
        foreach ($items as $item) {
            $failure = self::bulkItemFailure($item);
            if ($failure !== null) {
                $failures[] = $failure;
            }
        }
        if (($response['errors'] ?? false) === true || $failures !== []) {
            $first = $failures[0] ?? [null, null, null, null];

            throw new OpenSearchBulkException($failures === [] ? 1 : count($failures), ...$first);
        }

        return count($items);
    }

    /** @return list<string> */
    public function aliasTargets(string $alias, ?Cancellation $cancellation = null): array
    {
        try {
            $response = $this->request(
                'GET',
                '/_alias/' . rawurlencode($alias),
                cancellation: $cancellation,
            );
        } catch (OpenSearchException $exception) {
            if ($exception->status === 404) {
                return [];
            }

            throw $exception;
        }

        $targets = [];
        foreach ($response as $index => $definition) {
            if (!is_string($index) || !is_array($definition)) {
                continue;
            }
            $aliases = $definition['aliases'] ?? null;
            if (is_array($aliases) && array_key_exists($alias, $aliases)) {
                $targets[] = $index;
            }
        }
        sort($targets);

        return $targets;
    }

    public function switchAlias(
        string $alias,
        string $targetIndex,
        ?Cancellation $cancellation = null,
    ): void {
        if ($alias === $targetIndex) {
            throw new OpenSearchException('OpenSearch alias and physical index must be different.');
        }
        if ($this->concreteIndexExists($alias, $cancellation)) {
            throw new OpenSearchException(
                "OpenSearch alias `{$alias}` conflicts with a concrete index; migrate it without deleting live data.",
            );
        }
        if (!$this->concreteIndexExists($targetIndex, $cancellation)) {
            throw new OpenSearchException("OpenSearch target index `{$targetIndex}` does not exist.");
        }

        $actions = [];
        foreach ($this->aliasTargets($alias, $cancellation) as $existingIndex) {
            if ($existingIndex !== $targetIndex) {
                $actions[] = ['remove' => ['index' => $existingIndex, 'alias' => $alias]];
            }
        }
        $actions[] = ['add' => ['index' => $targetIndex, 'alias' => $alias]];

        $response = $this->request('POST', '/_aliases', body: ['actions' => $actions], cancellation: $cancellation);
        if (($response['acknowledged'] ?? null) !== true) {
            throw new OpenSearchException('OpenSearch did not acknowledge the alias switch.');
        }
    }

    /** @return array{?string, ?int, ?string, ?string}|null */
    private static function bulkItemFailure(mixed $item): ?array
    {
        if (!is_array($item) || array_is_list($item) || count($item) !== 1) {
            return [null, null, 'malformed_response', 'Bulk item must contain exactly one operation.'];
        }
        $operation = reset($item);
        if (!is_array($operation)) {
            return [null, null, 'malformed_response', 'Bulk operation result must be an object.'];
        }

        $status = $operation['status'] ?? null;
        $status = is_int($status) ? $status : null;
        $error = $operation['error'] ?? null;
        if ($error === null && $status !== null && $status >= 200 && $status < 300) {
            return null;
        }

        return [
            is_string($operation['_id'] ?? null) ? $operation['_id'] : null,
            $status,
            is_array($error) && is_string($error['type'] ?? null) ? $error['type'] : null,
            is_array($error) && is_string($error['reason'] ?? null) ? $error['reason'] : null,
        ];
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
