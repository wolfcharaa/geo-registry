<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\DataMos\Infrastructure;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\TimeoutCancellation;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;
use Throwable;

final readonly class DataMosSnapshotDownloader
{
    public const int DEFAULT_DATASET_ID = 60562;

    private const string BASE_URL = 'https://apidata.mos.ru/v1/datasets';
    private const int MAX_RESPONSE_BYTES = 67_108_864;

    public function __construct(
        private HttpClient $httpClient,
        private string $userAgent,
    ) {}

    public function inspect(string $apiKey, int $datasetId, int $timeoutSeconds): DataMosDatasetMetadata
    {
        self::assertSettings($apiKey, $datasetId, $timeoutSeconds);
        $version = $this->firstObject(
            $this->requestJson("{$datasetId}/version", $apiKey, [], $timeoutSeconds),
        );
        $versionNumber = self::optionalInteger($version, 'VersionNumber', 'versionNumber');
        $releaseNumber = self::optionalInteger($version, 'ReleaseNumber', 'releaseNumber');
        $query = [];
        if ($versionNumber !== null) {
            $query['versionNumber'] = $versionNumber;
        }
        if ($releaseNumber !== null) {
            $query['releaseNumber'] = $releaseNumber;
        }
        $count = $this->requestJson("{$datasetId}/count", $apiKey, $query, $timeoutSeconds);

        return new DataMosDatasetMetadata(
            datasetId: $datasetId,
            versionNumber: $versionNumber,
            releaseNumber: $releaseNumber,
            rows: $this->count($count),
        );
    }

    /**
     * @param null|callable(int $downloadedRows, int $advertisedRows): void $progress
     */
    public function download(
        string $destination,
        string $apiKey,
        int $datasetId = self::DEFAULT_DATASET_ID,
        int $pageSize = 500,
        ?int $limit = null,
        int $timeoutSeconds = 30,
        bool $force = false,
        ?callable $progress = null,
    ): DataMosSnapshotResult {
        if ($pageSize < 1 || $pageSize > 1000) {
            throw new RuntimeException('Data.Mos page size must be between 1 and 1000.');
        }
        if ($limit !== null && $limit < 1) {
            throw new RuntimeException('Data.Mos row limit must be positive.');
        }

        $destination = trim($destination);
        if ($destination === '' || (!str_ends_with($destination, '.jsonl') && !str_ends_with($destination, '.jsonl.gz'))) {
            throw new RuntimeException('Data.Mos destination must end with .jsonl or .jsonl.gz.');
        }
        if (is_link($destination)) {
            throw new RuntimeException('Data.Mos destination must not be a symbolic link.');
        }
        $manifestPath = $destination . '.manifest.json';
        if (!$force && (file_exists($destination) || file_exists($manifestPath))) {
            throw new RuntimeException('Data.Mos destination or manifest already exists; pass --force to replace both.');
        }

        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create Data.Mos destination directory: {$directory}");
        }
        if (!is_writable($directory)) {
            throw new RuntimeException("Data.Mos destination directory is not writable: {$directory}");
        }

        $metadata = $this->inspect($apiKey, $datasetId, $timeoutSeconds);
        $processId = getmypid();
        if ($processId === false) {
            throw new RuntimeException('Unable to determine the Data.Mos downloader process ID.');
        }
        $suffix = sprintf('.part.%d.%s', $processId, bin2hex(random_bytes(6)));
        $temporaryPath = $destination . $suffix;
        $temporaryManifestPath = $manifestPath . $suffix;
        $streamPath = str_ends_with($destination, '.gz') ? 'compress.zlib://' . $temporaryPath : $temporaryPath;
        $stream = fopen($streamPath, 'wb');
        if ($stream === false) {
            throw new RuntimeException("Unable to create Data.Mos snapshot: {$destination}");
        }

        $downloadedRows = 0;
        try {
            while ($limit === null || $downloadedRows < $limit) {
                $requestedRows = $limit === null
                    ? $pageSize
                    : min($pageSize, $limit - $downloadedRows);
                $query = [
                    '$top' => $requestedRows,
                    '$skip' => $downloadedRows,
                ];
                if ($metadata->versionNumber !== null) {
                    $query['versionNumber'] = $metadata->versionNumber;
                }
                if ($metadata->releaseNumber !== null) {
                    $query['releaseNumber'] = $metadata->releaseNumber;
                }

                $payload = $this->requestJson("{$datasetId}/rows", $apiKey, $query, $timeoutSeconds);
                $rows = $this->rows($payload);
                if ($rows === []) {
                    break;
                }

                foreach ($rows as $row) {
                    $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                    if (fwrite($stream, $line) !== strlen($line)) {
                        throw new RuntimeException('Unable to write the complete Data.Mos snapshot row.');
                    }
                    ++$downloadedRows;
                    if ($limit !== null && $downloadedRows >= $limit) {
                        break;
                    }
                }

                if ($progress !== null) {
                    $progress($downloadedRows, $metadata->rows);
                }
                if ($downloadedRows >= $metadata->rows) {
                    break;
                }
            }
            if ($limit === null && $downloadedRows !== $metadata->rows) {
                throw new RuntimeException(sprintf(
                    'Official Data.Mos snapshot is incomplete: downloaded %d of %d rows.',
                    $downloadedRows,
                    $metadata->rows,
                ));
            }
        } catch (Throwable $error) {
            fclose($stream);
            @unlink($temporaryPath);
            @unlink($temporaryManifestPath);
            throw $error;
        }
        if (!fclose($stream)) {
            @unlink($temporaryPath);
            throw new RuntimeException('Unable to finalize the Data.Mos snapshot stream.');
        }

        $bytes = filesize($temporaryPath);
        $sha256 = hash_file('sha256', $temporaryPath);
        if (!is_int($bytes) || $bytes < 1 || !is_string($sha256)) {
            @unlink($temporaryPath);
            throw new RuntimeException('Unable to calculate Data.Mos snapshot metadata.');
        }
        $manifest = [
            'format' => 'geo-registry-datamos-snapshot-v1',
            'dataset_id' => $metadata->datasetId,
            'source_url' => self::BASE_URL . '/' . $metadata->datasetId,
            'downloaded_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            'version_number' => $metadata->versionNumber,
            'release_number' => $metadata->releaseNumber,
            'advertised_rows' => $metadata->rows,
            'downloaded_rows' => $downloadedRows,
            'limited' => $limit !== null,
            'file' => basename($destination),
            'bytes' => $bytes,
            'sha256' => $sha256,
        ];
        $manifestJson = json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
        if (file_put_contents($temporaryManifestPath, $manifestJson, LOCK_EX) !== strlen($manifestJson)) {
            @unlink($temporaryPath);
            @unlink($temporaryManifestPath);
            throw new RuntimeException('Unable to write the complete Data.Mos snapshot manifest.');
        }

        if (!rename($temporaryPath, $destination)) {
            @unlink($temporaryPath);
            @unlink($temporaryManifestPath);
            throw new RuntimeException('Unable to publish the Data.Mos snapshot atomically.');
        }
        if (!rename($temporaryManifestPath, $manifestPath)) {
            @unlink($destination);
            @unlink($temporaryManifestPath);
            throw new RuntimeException('Unable to publish the Data.Mos snapshot manifest atomically.');
        }

        return new DataMosSnapshotResult(
            path: $destination,
            manifestPath: $manifestPath,
            rows: $downloadedRows,
            bytes: $bytes,
            sha256: $sha256,
            dataset: $metadata,
        );
    }

    /**
     * @param array<string, int|string> $query
     * @return array<array-key, mixed>|bool|float|int|string|null
     */
    private function requestJson(string $endpoint, string $apiKey, array $query, int $timeoutSeconds): array|bool|float|int|string|null
    {
        $query['api_key'] = $apiKey;
        $request = new Request(self::BASE_URL . '/' . $endpoint . '?' . http_build_query($query));
        $request->setHeader('Accept', 'application/json');
        $request->setHeader('User-Agent', $this->userAgent);
        $request->setBodySizeLimit(self::MAX_RESPONSE_BYTES);

        try {
            $response = $this->httpClient->request($request, new TimeoutCancellation($timeoutSeconds));
            $body = $response->getBody()->buffer();
        } catch (Throwable $error) {
            throw new RuntimeException('Official Data.Mos API request failed.', previous: $error);
        }
        if ($response->getStatus() < 200 || $response->getStatus() >= 300) {
            throw new RuntimeException(sprintf('Official Data.Mos API returned HTTP %d.', $response->getStatus()));
        }

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Official Data.Mos API returned invalid JSON.', previous: $error);
        }
        if ($payload !== null && !is_array($payload) && !is_scalar($payload)) {
            throw new RuntimeException('Official Data.Mos API returned an unsupported JSON value.');
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function firstObject(mixed $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }
        if (array_is_list($payload)) {
            $payload = $payload[0] ?? [];
        }

        if (!is_array($payload)) {
            return [];
        }

        $object = [];
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $object[$key] = $value;
            }
        }

        return $object;
    }

    private function count(mixed $payload): int
    {
        if (is_int($payload) || (is_string($payload) && ctype_digit($payload))) {
            return (int) $payload;
        }
        $object = $this->firstObject($payload);
        $count = self::optionalInteger($object, 'Count', 'count');
        if ($count === null || $count < 0) {
            throw new RuntimeException('Official Data.Mos API returned an invalid dataset row count.');
        }

        return $count;
    }

    /** @return list<array<string, mixed>> */
    private function rows(mixed $payload): array
    {
        if (!is_array($payload) || !array_is_list($payload)) {
            throw new RuntimeException('Official Data.Mos rows response must be a JSON list.');
        }

        $rows = [];
        foreach ($payload as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new RuntimeException('Official Data.Mos rows response contains an invalid row.');
            }
            $record = [];
            foreach ($row as $key => $value) {
                if (is_string($key)) {
                    $record[$key] = $value;
                }
            }
            $rows[] = $record;
        }

        return $rows;
    }

    /** @param array<string, mixed> $object */
    private static function optionalInteger(array $object, string ...$keys): ?int
    {
        foreach ($keys as $key) {
            $value = $object[$key] ?? null;
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                return (int) $value;
            }
        }

        return null;
    }

    private static function assertSettings(string $apiKey, int $datasetId, int $timeoutSeconds): void
    {
        if (trim($apiKey) === '') {
            throw new RuntimeException('DATAMOS_API_KEY is required for the official Data.Mos API.');
        }
        if ($datasetId < 1) {
            throw new RuntimeException('Data.Mos dataset ID must be positive.');
        }
        if ($timeoutSeconds < 1 || $timeoutSeconds > 120) {
            throw new RuntimeException('Data.Mos timeout must be between 1 and 120 seconds.');
        }
    }
}
