<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\DataMos\Infrastructure;

use function array_is_list;
use function array_key_exists;
use function count;
use function fclose;
use function fgets;
use function file_get_contents;
use function fopen;
use function hash;
use function http_build_query;
use function in_array;
use function is_array;
use function is_file;
use function is_numeric;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;

use JsonException;

use function max;
use function microtime;

use PDO;
use PDOStatement;

use function preg_match;
use function rtrim;

use RuntimeException;

use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function stream_context_create;
use function strtolower;
use function trim;

final class DataMosAddressRegistryImporter
{
    public const APICRAFTER_SOURCE = 'https://api.crftr.net/open/rawapi/v3/datamos/addressreestr';

    public function __construct(
        private readonly PDO $db,
        private readonly BsonDocumentStream $bsonDocuments,
    ) {}

    /**
     * @param null|callable(int $readRows, int $writtenRows, int $skippedRows, string $phase): void $progress
     */
    public function import(
        string $source,
        int $batchSize = 1000,
        int $pageSize = 1000,
        ?int $limit = null,
        bool $truncate = false,
        bool $dryRun = false,
        ?callable $progress = null,
    ): DataMosAddressRegistryImportResult {
        $source = trim($source);
        if ($source === '') {
            throw new RuntimeException('Data.Mos source is required.');
        }
        $this->validateSource($source);

        $batchSize = max(1, $batchSize);
        $pageSize = max(1, $pageSize);
        $startedAt = microtime(true);
        $readRows = 0;
        $writtenRows = 0;
        $skippedRows = 0;
        $batch = [];

        if ($truncate && !$dryRun) {
            $this->pdo()->exec('TRUNCATE TABLE datamos_address_registry RESTART IDENTITY');
        }

        foreach ($this->items($source, $pageSize) as $item) {
            if ($limit !== null && $readRows >= $limit) {
                break;
            }

            $readRows++;
            $row = $this->normalizeRow($item);
            if ($row === null) {
                $skippedRows++;
                continue;
            }

            $batch[] = $row;
            if (count($batch) < $batchSize) {
                continue;
            }

            $writtenRows += $dryRun ? count($batch) : $this->writeBatch($batch);
            $batch = [];

            if ($progress !== null) {
                $progress($readRows, $writtenRows, $skippedRows, 'batch');
            }
        }

        if ($batch !== []) {
            $writtenRows += $dryRun ? count($batch) : $this->writeBatch($batch);
            if ($progress !== null) {
                $progress($readRows, $writtenRows, $skippedRows, 'batch');
            }
        }

        return new DataMosAddressRegistryImportResult(
            source: $source,
            readRows: $readRows,
            writtenRows: $writtenRows,
            skippedRows: $skippedRows,
            duration: microtime(true) - $startedAt,
            dryRun: $dryRun,
        );
    }

    /**
     * @return iterable<array<string, mixed>>
     */
    private function items(string $source, int $pageSize): iterable
    {
        if (str_starts_with($source, 'http://') || str_starts_with($source, 'https://')) {
            yield from $this->apiItems($source, $pageSize);

            return;
        }

        if (!is_file($source)) {
            throw new RuntimeException(sprintf('Data.Mos source file not found: %s', $source));
        }

        $lowerSource = strtolower($source);

        if (str_ends_with($lowerSource, '.bson') || str_ends_with($lowerSource, '.bson.gz')) {
            yield from $this->bsonDocuments->read($source);

            return;
        }

        if (str_ends_with($lowerSource, '.jsonl') || str_ends_with($lowerSource, '.ndjson')) {
            yield from $this->jsonLines($source);

            return;
        }

        if (str_ends_with($lowerSource, '.zip')) {
            throw new RuntimeException(
                'Data.Mos zip package must be extracted first. Import data.bson.gz from the package.',
            );
        }

        yield from $this->jsonFile($source);
    }

    private function validateSource(string $source): void
    {
        if (str_starts_with($source, 'http://') || str_starts_with($source, 'https://')) {
            return;
        }

        if (!is_file($source)) {
            throw new RuntimeException(sprintf('Data.Mos source file not found: %s', $source));
        }

        if (str_ends_with(strtolower($source), '.zip')) {
            throw new RuntimeException(
                'Data.Mos zip package must be extracted first. Import data.bson.gz from the package.',
            );
        }
    }

    /**
     * @return iterable<array<string, mixed>>
     */
    private function apiItems(string $source, int $pageSize): iterable
    {
        $page = 1;
        while (true) {
            $payload = $this->fetchJson($this->urlWithQuery($source, [
                'page' => $page,
                'max_results' => $pageSize,
            ]));
            $items = $this->itemsFromPayload($payload);

            if ($items === []) {
                return;
            }

            foreach ($items as $item) {
                yield $item;
            }

            $total = is_array($payload['_meta'] ?? null) && is_numeric($payload['_meta']['total'] ?? null)
                ? (int) $payload['_meta']['total']
                : null;

            if ($total !== null && $page * $pageSize >= $total) {
                return;
            }

            if (count($items) < $pageSize) {
                return;
            }

            $page++;
        }
    }

    /**
     * @return iterable<array<string, mixed>>
     */
    private function jsonLines(string $path): iterable
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open Data.Mos JSONL file: %s', $path));
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    /** @var array<string, mixed> $decoded */
                    yield $decoded;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return iterable<array<string, mixed>>
     */
    private function jsonFile(string $path): iterable
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read Data.Mos JSON file: %s', $path));
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('Invalid Data.Mos JSON file %s: %s', $path, $e->getMessage()), 0, $e);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Data.Mos JSON file %s must contain an object or array.', $path));
        }

        foreach ($this->itemsFromPayload($decoded) as $item) {
            yield $item;
        }
    }

    /** @return array<int|string, mixed> */
    private function fetchJson(string $url): array
    {
        $contents = @file_get_contents($url, false, stream_context_create([
            'http' => [
                'header' => "Accept: application/json\r\nUser-Agent: geo-registry-datamos-import/1.0\r\n",
                'ignore_errors' => true,
                'timeout' => 30,
            ],
        ]));
        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to fetch Data.Mos URL: %s', $url));
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('Data.Mos URL returned invalid JSON: %s', $e->getMessage()), 0, $e);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Data.Mos URL must return a JSON object or array.');
        }

        return $decoded;
    }

    /**
     * @param array<int|string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function itemsFromPayload(array $payload): array
    {
        if (array_is_list($payload)) {
            return $this->onlyArrayItems($payload);
        }

        foreach (['_items', 'items', 'Items', 'rows', 'Rows', 'features', 'Features', 'data', 'Data'] as $key) {
            if (is_array($payload[$key] ?? null)) {
                return $this->onlyArrayItems($payload[$key]);
            }
        }

        return [];
    }

    /**
     * @param array<mixed> $items
     * @return list<array<string, mixed>>
     */
    private function onlyArrayItems(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            /** @var array<string, mixed> $item */
            $result[] = $item;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $rawItem
     * @return array<string, mixed>|null
     */
    private function normalizeRow(array $rawItem): ?array
    {
        $item = $this->unwrapItem($rawItem);
        $address = $this->stringField($item, 'ADDRESS');
        $simpleAddress = $this->stringField($item, 'SIMPLE_ADDRESS');

        if ($address === '' && $simpleAddress !== '') {
            $address = $simpleAddress;
        }

        if ($address === '') {
            return null;
        }

        $globalId = $this->intField($item, 'global_id');
        $unom = $this->intField($item, 'UNOM');
        $nreg = $this->intField($item, 'NREG');
        $nFias = $this->uuidField($item, 'N_FIAS');
        [$geoLon, $geoLat] = $this->point($item, $rawItem);

        return [
            'source_key' => $this->sourceKey($item, $address, $globalId, $unom, $nreg, $nFias),
            'source_id' => $this->stringField($item, '_id'),
            'global_id' => $globalId,
            'unom' => $unom,
            'nreg' => $nreg,
            'n_fias' => $nFias,
            'd_fias' => $this->stringField($item, 'D_FIAS'),
            'obj_type' => $this->stringField($item, 'OBJ_TYPE'),
            'on_territory_of_moscow' => $this->boolField($item, 'OnTerritoryOfMoscow'),
            'address' => $address,
            'simple_address' => $simpleAddress,
            'p0' => $this->stringField($item, 'P0'),
            'p1' => $this->stringField($item, 'P1'),
            'p2' => $this->stringField($item, 'P2'),
            'p3' => $this->stringField($item, 'P3'),
            'p4' => $this->stringField($item, 'P4'),
            'p5' => $this->stringField($item, 'P5'),
            'p6' => $this->stringField($item, 'P6'),
            'p7' => $this->stringField($item, 'P7'),
            'l1_type' => $this->stringField($item, 'L1_TYPE'),
            'l1_value' => $this->stringField($item, 'L1_VALUE'),
            'l2_type' => $this->stringField($item, 'L2_TYPE'),
            'l2_value' => $this->stringField($item, 'L2_VALUE'),
            'l3_type' => $this->stringField($item, 'L3_TYPE'),
            'l3_value' => $this->stringField($item, 'L3_VALUE'),
            'l4_value' => $this->stringField($item, 'L4_VALUE'),
            'l5_value' => $this->stringField($item, 'L5_VALUE'),
            'adm_area' => $this->stringField($item, 'ADM_AREA'),
            'district' => $this->stringField($item, 'DISTRICT'),
            'kad_n' => $this->jsonField($item, 'KAD_N'),
            'kad_zu' => $this->jsonField($item, 'KAD_ZU'),
            'kladr' => $this->stringField($item, 'KLADR'),
            'tdoc' => $this->stringField($item, 'TDOC'),
            'ndoc' => $this->stringField($item, 'NDOC'),
            'ddoc' => $this->stringField($item, 'DDOC'),
            'adr_type' => $this->stringField($item, 'ADR_TYPE'),
            'vid' => $this->stringField($item, 'VID'),
            'sostad' => $this->stringField($item, 'SOSTAD'),
            'status' => $this->stringField($item, 'STATUS'),
            'geo_lat' => $geoLat,
            'geo_lon' => $geoLon,
            'geo_data' => $this->jsonField($item, 'geoData'),
            'geodata_center' => $this->jsonField($item, 'geodata_center'),
            'raw_payload' => json_encode($rawItem, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function unwrapItem(array $item): array
    {
        if (is_array($item['Cells'] ?? null)) {
            $cells = $this->stringKeyedArray($item['Cells']);
            foreach (['_id', 'global_id'] as $field) {
                if (array_key_exists($field, $item) && !array_key_exists($field, $cells)) {
                    $cells[$field] = $item[$field];
                }
            }

            return $this->stringKeyedArray($cells);
        }

        if (is_array($item['properties'] ?? null)) {
            $properties = $this->stringKeyedArray($item['properties']);
            foreach (['_id', 'global_id'] as $field) {
                if (array_key_exists($field, $item) && !array_key_exists($field, $properties)) {
                    $properties[$field] = $item[$field];
                }
            }

            if (is_array($item['geometry'] ?? null) && !array_key_exists('geometry', $properties)) {
                $properties['geometry'] = $item['geometry'];
            }

            return $this->stringKeyedArray($properties);
        }

        return $this->stringKeyedArray($item);
    }

    /**
     * @param array<mixed> $values
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $rawItem
     * @return array{0: float|null, 1: float|null}
     */
    private function point(array $item, array $rawItem): array
    {
        foreach (['geodata_center', 'geoData', 'geometry'] as $field) {
            $point = $this->pointFromValue($item[$field] ?? null);
            if ($point !== null) {
                return $point;
            }
        }

        if (is_array($rawItem['geometry'] ?? null)) {
            $point = $this->pointFromValue($rawItem['geometry']);
            if ($point !== null) {
                return $point;
            }
        }

        return [null, null];
    }

    /**
     * @return array{0: float, 1: float}|null
     */
    private function pointFromValue(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $rawType = $value['type'] ?? '';
        if (!is_scalar($rawType)) {
            return null;
        }

        $type = strtolower(trim((string) $rawType));
        if (!in_array($type, ['', 'point'], true)) {
            return null;
        }

        $coordinates = $value['coordinates'] ?? null;
        if (!is_array($coordinates) || count($coordinates) < 2) {
            return null;
        }

        $lon = $coordinates[0] ?? null;
        $lat = $coordinates[1] ?? null;
        if (!is_numeric($lon) || !is_numeric($lat)) {
            return null;
        }

        $lon = (float) $lon;
        $lat = (float) $lat;
        if ($lat < -90.0 || $lat > 90.0 || $lon < -180.0 || $lon > 180.0) {
            return null;
        }

        return [$lon, $lat];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function stringField(array $item, string $name): string
    {
        $value = $this->field($item, $name);
        if ($value === null || !is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function intField(array $item, string $name): ?int
    {
        $value = $this->field($item, $name);
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function uuidField(array $item, string $name): ?string
    {
        $value = $this->stringField($item, $name);
        if ($value === '') {
            return null;
        }

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1
            ? strtolower($value)
            : null;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function boolField(array $item, string $name): ?bool
    {
        $value = $this->field($item, $name);
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (!is_scalar($value)) {
            return null;
        }

        $value = strtolower(trim((string) $value));

        return match ($value) {
            '1', 'true', 'yes', 'y', 'да' => true,
            '0', 'false', 'no', 'n', 'нет' => false,
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $item
     */
    private function jsonField(array $item, string $name): string
    {
        $value = $this->field($item, $name);
        if ($value === null || $value === '') {
            $value = [];
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function field(array $item, string $name): mixed
    {
        if (array_key_exists($name, $item)) {
            return $item[$name];
        }

        $lower = strtolower($name);
        foreach ($item as $key => $value) {
            if (is_string($key) && strtolower($key) === $lower) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function sourceKey(
        array $item,
        string $address,
        ?int $globalId,
        ?int $unom,
        ?int $nreg,
        ?string $nFias,
    ): string {
        if ($globalId !== null) {
            return 'global:' . $globalId;
        }

        if ($nreg !== null) {
            return 'nreg:' . $nreg;
        }

        if ($unom !== null) {
            return 'unom:' . $unom;
        }

        if ($nFias !== null) {
            return 'fias:' . $nFias;
        }

        return 'hash:' . hash(
            'sha256',
            $address . '|' . json_encode($item, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param array<string, mixed> $query
     */
    private function urlWithQuery(string $url, array $query): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return rtrim($url, '?&') . $separator . http_build_query($query);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function writeBatch(array $rows): int
    {
        $pdo = $this->pdo();
        $statement = $this->insertStatement($pdo);
        $pdo->beginTransaction();

        try {
            foreach ($rows as $row) {
                $this->bindRow($statement, $row);
                $statement->execute();
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }

        return count($rows);
    }

    private function insertStatement(PDO $pdo): PDOStatement
    {
        $statement = $pdo->prepare(<<<'SQL'
INSERT INTO datamos_address_registry (
    source_key,
    source_id,
    global_id,
    unom,
    nreg,
    n_fias,
    d_fias,
    obj_type,
    on_territory_of_moscow,
    address,
    simple_address,
    p0,
    p1,
    p2,
    p3,
    p4,
    p5,
    p6,
    p7,
    l1_type,
    l1_value,
    l2_type,
    l2_value,
    l3_type,
    l3_value,
    l4_value,
    l5_value,
    adm_area,
    district,
    kad_n,
    kad_zu,
    kladr,
    tdoc,
    ndoc,
    ddoc,
    adr_type,
    vid,
    sostad,
    status,
    geo_lat,
    geo_lon,
    geo_data,
    geodata_center,
    raw_payload
) VALUES (
    :source_key,
    :source_id,
    :global_id,
    :unom,
    :nreg,
    CAST(:n_fias AS uuid),
    :d_fias,
    :obj_type,
    :on_territory_of_moscow,
    :address,
    :simple_address,
    :p0,
    :p1,
    :p2,
    :p3,
    :p4,
    :p5,
    :p6,
    :p7,
    :l1_type,
    :l1_value,
    :l2_type,
    :l2_value,
    :l3_type,
    :l3_value,
    :l4_value,
    :l5_value,
    :adm_area,
    :district,
    CAST(:kad_n AS jsonb),
    CAST(:kad_zu AS jsonb),
    :kladr,
    :tdoc,
    :ndoc,
    :ddoc,
    :adr_type,
    :vid,
    :sostad,
    :status,
    :geo_lat,
    :geo_lon,
    CAST(:geo_data AS jsonb),
    CAST(:geodata_center AS jsonb),
    CAST(:raw_payload AS jsonb)
)
ON CONFLICT (source_key) DO UPDATE SET
    source_id = EXCLUDED.source_id,
    global_id = EXCLUDED.global_id,
    unom = EXCLUDED.unom,
    nreg = EXCLUDED.nreg,
    n_fias = EXCLUDED.n_fias,
    d_fias = EXCLUDED.d_fias,
    obj_type = EXCLUDED.obj_type,
    on_territory_of_moscow = EXCLUDED.on_territory_of_moscow,
    address = EXCLUDED.address,
    simple_address = EXCLUDED.simple_address,
    p0 = EXCLUDED.p0,
    p1 = EXCLUDED.p1,
    p2 = EXCLUDED.p2,
    p3 = EXCLUDED.p3,
    p4 = EXCLUDED.p4,
    p5 = EXCLUDED.p5,
    p6 = EXCLUDED.p6,
    p7 = EXCLUDED.p7,
    l1_type = EXCLUDED.l1_type,
    l1_value = EXCLUDED.l1_value,
    l2_type = EXCLUDED.l2_type,
    l2_value = EXCLUDED.l2_value,
    l3_type = EXCLUDED.l3_type,
    l3_value = EXCLUDED.l3_value,
    l4_value = EXCLUDED.l4_value,
    l5_value = EXCLUDED.l5_value,
    adm_area = EXCLUDED.adm_area,
    district = EXCLUDED.district,
    kad_n = EXCLUDED.kad_n,
    kad_zu = EXCLUDED.kad_zu,
    kladr = EXCLUDED.kladr,
    tdoc = EXCLUDED.tdoc,
    ndoc = EXCLUDED.ndoc,
    ddoc = EXCLUDED.ddoc,
    adr_type = EXCLUDED.adr_type,
    vid = EXCLUDED.vid,
    sostad = EXCLUDED.sostad,
    status = EXCLUDED.status,
    geo_lat = EXCLUDED.geo_lat,
    geo_lon = EXCLUDED.geo_lon,
    geo_data = EXCLUDED.geo_data,
    geodata_center = EXCLUDED.geodata_center,
    raw_payload = EXCLUDED.raw_payload,
    updated_at = CURRENT_TIMESTAMP
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare Data.Mos upsert statement.');
        }

        return $statement;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function bindRow(PDOStatement $statement, array $row): void
    {
        foreach ($row as $name => $value) {
            $parameter = ':' . $name;

            if ($value === null || $value === '') {
                $statement->bindValue($parameter, null, PDO::PARAM_NULL);
                continue;
            }

            if (is_bool($value)) {
                $statement->bindValue($parameter, $value, PDO::PARAM_BOOL);
                continue;
            }

            if (is_int($value)) {
                $statement->bindValue($parameter, $value, PDO::PARAM_INT);
                continue;
            }

            $statement->bindValue($parameter, $value, PDO::PARAM_STR);
        }
    }

    private function pdo(): PDO
    {
        return $this->db;
    }
}
