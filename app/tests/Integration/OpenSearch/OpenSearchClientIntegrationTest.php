<?php

declare(strict_types=1);

namespace App\Tests\Integration\OpenSearch;

use App\Infrastructure\Http\AmpHttpClientFactory;
use App\Infrastructure\OpenSearch\OpenSearchClientFactory;
use RuntimeException;
use stdClass;
use Testo\Assert;
use Testo\Test;

#[Test]
final class OpenSearchClientIntegrationTest
{
    public function testItUsesTheNativeClientAgainstAnEphemeralIndex(): void
    {
        $httpClient = AmpHttpClientFactory::create(10);
        $client = (new OpenSearchClientFactory($httpClient))->create(
            self::environment('INTEGRATION_OPENSEARCH_URL', 'http://127.0.0.1:59200'),
            'geo-registry-integration-test/0.1',
        );
        $index = 'geo-registry-integration-' . bin2hex(random_bytes(6));

        $health = $client->health();
        Assert::true(in_array($health['status'] ?? null, ['green', 'yellow'], true));

        try {
            $created = $client->request('PUT', '/' . $index);
            Assert::same($created['acknowledged'] ?? null, true);

            $result = $client->search($index, ['match_all' => new stdClass()]);
            $hits = $result['hits'] ?? null;
            if (!is_array($hits)) {
                throw new RuntimeException('OpenSearch search response does not contain a hits object.');
            }
            $total = $hits['total'] ?? null;
            if (!is_array($total)) {
                throw new RuntimeException('OpenSearch search response does not contain a total object.');
            }
            Assert::same($total['value'] ?? null, 0);
        } finally {
            $deleted = $client->request('DELETE', '/' . $index);
            Assert::same($deleted['acknowledged'] ?? null, true);
        }
    }

    private static function environment(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
