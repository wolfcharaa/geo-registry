<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\OpenSearch;

use Amp\Cancellation;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use App\Infrastructure\OpenSearch\OpenSearchBulkException;
use App\Infrastructure\OpenSearch\OpenSearchClient;
use App\Infrastructure\OpenSearch\OpenSearchRequestFactory;
use LogicException;
use Testo\Assert;
use Testo\Test;

#[Test]
final class OpenSearchBulkClientTest
{
    public function testItAcceptsOnlyACompleteSuccessfulBulkResponse(): void
    {
        $http = new BulkResponseHttpClient(json_encode([
            'errors' => false,
            'items' => [
                ['index' => ['_id' => '77:adm:1', 'status' => 201]],
                ['index' => ['_id' => '77:adm:2', 'status' => 200]],
            ],
        ], JSON_THROW_ON_ERROR));

        $indexed = self::client($http)->bulkIndex(
            'addresses-v1',
            "{\"index\":{\"_id\":\"77:adm:1\"}}\n{}\n{\"index\":{\"_id\":\"77:adm:2\"}}\n{}\n",
            2,
        );

        Assert::same($indexed, 2);
        Assert::notNull($http->request);
        Assert::same($http->request->getUri()->getPath(), '/addresses-v1/_bulk');
        Assert::same($http->request->getHeader('content-type'), 'application/x-ndjson');
    }

    public function testItSurfacesTheFirstItemFailureWithoutRetrying(): void
    {
        $http = new BulkResponseHttpClient(json_encode([
            'errors' => true,
            'items' => [[
                'index' => [
                    '_id' => '77:adm:1',
                    'status' => 400,
                    'error' => [
                        'type' => 'mapper_parsing_exception',
                        'reason' => 'failed to parse field',
                    ],
                ],
            ]],
        ], JSON_THROW_ON_ERROR));

        try {
            self::client($http)->bulkIndex('addresses-v1', "{}\n{}\n", 1);
        } catch (OpenSearchBulkException $error) {
            Assert::same($error->failedItems, 1);
            Assert::same($error->documentId, '77:adm:1');
            Assert::same($error->itemStatus, 400);
            Assert::same($error->errorType, 'mapper_parsing_exception');
            Assert::same($http->requests, 1);

            return;
        }

        throw new LogicException('Expected an item-level OpenSearch bulk failure.');
    }

    private static function client(DelegateHttpClient $http): OpenSearchClient
    {
        return new OpenSearchClient(
            new HttpClient($http, []),
            new OpenSearchRequestFactory('http://opensearch.test:9200', 'test/1.0'),
        );
    }
}

final class BulkResponseHttpClient implements DelegateHttpClient
{
    public ?Request $request = null;
    public int $requests = 0;

    public function __construct(
        private readonly string $body,
    ) {}

    public function request(Request $request, Cancellation $cancellation): Response
    {
        ++$this->requests;
        $this->request = $request;
        $cancellation->throwIfRequested();

        return new Response('1.1', 200, null, ['content-type' => 'application/json'], $this->body, $request);
    }
}
