<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry\Infrastructure\Http;

use Amp\Cancellation;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use App\Module\AddressRegistry\Infrastructure\Http\HttpGarReleaseMetadataReader;
use Testo\Assert;
use Testo\Test;

#[Test]
final class HttpGarReleaseMetadataReaderTest
{
    public function testItMapsAndSortsTheOfficialWrappedMetadataShape(): void
    {
        $http = new GarMetadataResponseHttpClient(json_encode([
            'GetAllDownloadFileInfoResult' => [
                [
                    'VersionId' => 10,
                    'TextVersion' => 'Версия от 01.11.2026',
                    'GarXMLFullURL' => 'https://files.test/2026-11-01/full.zip',
                ],
                [
                    'versionId' => '12',
                    'Date' => '/Date(1793836800000)/',
                    'FiasCompleteXmlUrl' => 'https://files.test/latest.zip',
                    'FiasDeltaXmlUrl' => 'https://files.test/delta.zip',
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        $reader = new HttpGarReleaseMetadataReader(
            new HttpClient($http, []),
            'geo-registry-test/1.0',
        );

        $releases = $reader->all(null, 5);

        Assert::same(count($releases), 2);
        $first = $releases[0] ?? null;
        $second = $releases[1] ?? null;
        Assert::notNull($first);
        Assert::notNull($second);
        Assert::same($first->versionId, 12);
        Assert::same($first->date, '2026-11-05');
        Assert::same($first->deltaXmlUrl, 'https://files.test/delta.zip');
        Assert::same($second->versionId, 10);
        Assert::same($second->date, '2026-11-01');
        Assert::notNull($http->request);
        Assert::same(
            (string) $http->request->getUri(),
            'https://fias-file.nalog.ru/WebServices/Public/GetAllDownloadFileInfo',
        );
        Assert::same($http->request->getHeader('user-agent'), 'geo-registry-test/1.0');
        Assert::same($http->request->getBodySizeLimit(), 4_194_304);
    }
}

final class GarMetadataResponseHttpClient implements DelegateHttpClient
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
