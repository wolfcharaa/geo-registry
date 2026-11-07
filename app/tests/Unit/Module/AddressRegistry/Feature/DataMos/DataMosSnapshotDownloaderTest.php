<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry\Feature\DataMos;

use Amp\Cancellation;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use App\Module\AddressRegistry\Feature\DataMos\Infrastructure\BsonDocumentStream;
use App\Module\AddressRegistry\Feature\DataMos\Infrastructure\DataMosAddressRegistryImporter;
use App\Module\AddressRegistry\Feature\DataMos\Infrastructure\DataMosSnapshotDownloader;
use PDO;
use ReflectionClass;
use Testo\Assert;
use Testo\Test;

#[Test]
final class DataMosSnapshotDownloaderTest
{
    public function testItWritesAnOfficialVersionPinnedSnapshotAndProvenanceManifest(): void
    {
        $transport = new DataMosApiResponseHttpClient();
        $downloader = new DataMosSnapshotDownloader(new HttpClient($transport, []), 'geo-registry/test');
        $directory = sys_get_temp_dir() . '/geo-registry-datamos-' . bin2hex(random_bytes(8));
        $destination = $directory . '/addressreestr.jsonl.gz';

        try {
            $result = $downloader->download(
                destination: $destination,
                apiKey: 'secret-api-key',
                pageSize: 2,
            );

            Assert::same($result->rows, 2);
            Assert::same($result->dataset->versionNumber, 3);
            Assert::same($result->dataset->releaseNumber, 165);
            Assert::same(
                file_get_contents('compress.zlib://' . $destination),
                "{\"Cells\":{\"ADDRESS\":\"Москва, Тестовая, 1\"}}\n"
                . "{\"Cells\":{\"ADDRESS\":\"Москва, Тестовая, 2\"}}\n",
            );

            $manifest = file_get_contents($result->manifestPath);
            Assert::true(is_string($manifest));
            Assert::true(str_contains($manifest, 'geo-registry-datamos-snapshot-v1'));
            Assert::true(str_contains($manifest, '"version_number": 3'));
            Assert::false(str_contains($manifest, 'secret-api-key'));

            $rowsRequest = $transport->requests[2] ?? null;
            Assert::notNull($rowsRequest);
            parse_str($rowsRequest->getUri()->getQuery(), $query);
            Assert::same($query['$top'] ?? null, '2');
            Assert::same($query['$skip'] ?? null, '0');
            Assert::same($query['versionNumber'] ?? null, '3');
            Assert::same($query['releaseNumber'] ?? null, '165');

            $pdo = (new ReflectionClass(PDO::class))->newInstanceWithoutConstructor();
            $import = (new DataMosAddressRegistryImporter($pdo, new BsonDocumentStream()))->import(
                source: $destination,
                dryRun: true,
                requireManifest: true,
            );
            Assert::same($import->readRows, 2);
            Assert::same($import->writtenRows, 2);
        } finally {
            @unlink($destination . '.manifest.json');
            @unlink($destination);
            @rmdir($directory);
        }
    }
}

final class DataMosApiResponseHttpClient implements DelegateHttpClient
{
    /** @var list<Request> */
    public array $requests = [];

    public function request(Request $request, Cancellation $cancellation): Response
    {
        $this->requests[] = $request;
        $cancellation->throwIfRequested();
        $path = $request->getUri()->getPath();
        $body = match (true) {
            str_ends_with($path, '/version') => '[{"VersionNumber":3,"ReleaseNumber":165}]',
            str_ends_with($path, '/count') => '2',
            str_ends_with($path, '/rows') => '[{"Cells":{"ADDRESS":"Москва, Тестовая, 1"}},{"Cells":{"ADDRESS":"Москва, Тестовая, 2"}}]',
            default => 'null',
        };

        return new Response('1.1', 200, null, ['content-type' => 'application/json'], $body, $request);
    }
}
