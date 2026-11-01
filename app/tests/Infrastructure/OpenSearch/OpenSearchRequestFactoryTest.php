<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\OpenSearch;

use App\Infrastructure\OpenSearch\OpenSearchRequestFactory;
use InvalidArgumentException;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Test;

#[Test]
final class OpenSearchRequestFactoryTest
{
    public function testItBuildsEncodedRequestWithBooleanParametersAndBasicAuth(): void
    {
        $factory = new OpenSearchRequestFactory(
            'https://opensearch.example:9200/',
            'test-service/1.0',
            'reader',
            'secret',
        );

        $request = $factory->create('get', '/_cluster/health', [
            'local' => false,
            'wait_for_status' => 'yellow',
        ]);

        Assert::same(
            (string) $request->getUri(),
            'https://opensearch.example:9200/_cluster/health?local=false&wait_for_status=yellow',
        );
        Assert::same($request->getHeader('Authorization'), 'Basic ' . base64_encode('reader:secret'));
        Assert::same($request->getHeader('User-Agent'), 'test-service/1.0');
    }

    #[ExpectException(InvalidArgumentException::class)]
    public function testItRejectsAPathContainingAQueryString(): void
    {
        $factory = new OpenSearchRequestFactory('http://opensearch:9200', 'test-service/1.0');

        $factory->create('GET', '/_cluster/health?pretty=true');
    }

    #[ExpectException(InvalidArgumentException::class)]
    public function testItRejectsAnEmptyHttpMethod(): void
    {
        $factory = new OpenSearchRequestFactory('http://opensearch:9200', 'test-service/1.0');

        $factory->create(' ', '/_cluster/health');
    }
}
