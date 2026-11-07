<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\OpenSearch;

use Amp\Cancellation;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use App\Infrastructure\OpenSearch\OpenSearchClient;
use App\Infrastructure\OpenSearch\OpenSearchRequestFactory;
use Testo\Assert;
use Testo\Test;

#[Test]
final class OpenSearchIndexInventoryTest
{
    public function testItReturnsOnlySortedConcreteIndexesWithTheRequestedPrefix(): void
    {
        $http = new IndexInventoryHttpClient(json_encode([
            ['index' => 'other-v1'],
            ['index' => 'places-v2'],
            ['index' => 'places-v1'],
            ['index' => '.plugins-ml-config'],
        ], JSON_THROW_ON_ERROR));
        $client = new OpenSearchClient(
            new HttpClient($http, []),
            new OpenSearchRequestFactory('http://opensearch.test:9200', 'test/1.0'),
        );

        Assert::same($client->concreteIndexes('places-v'), ['places-v1', 'places-v2']);
        Assert::notNull($http->request);
        Assert::same($http->request->getUri()->getPath(), '/_cat/indices');
        Assert::same($http->request->getUri()->getQuery(), 'format=json&h=index');
    }
}

final class IndexInventoryHttpClient implements DelegateHttpClient
{
    public ?Request $request = null;

    public function __construct(private readonly string $body) {}

    public function request(Request $request, Cancellation $cancellation): Response
    {
        $this->request = $request;
        $cancellation->throwIfRequested();

        return new Response('1.1', 200, null, ['content-type' => 'application/json'], $this->body, $request);
    }
}
