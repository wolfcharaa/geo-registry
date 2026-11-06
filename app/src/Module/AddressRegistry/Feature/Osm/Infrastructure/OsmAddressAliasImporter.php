<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\Osm\Infrastructure;

use function array_key_exists;
use function count;
use function fclose;
use function fgets;
use function fopen;
use function gzclose;
use function gzgets;
use function gzopen;
use function hash;
use function implode;
use function in_array;
use function is_array;
use function is_file;
use function is_int;
use function is_numeric;
use function is_scalar;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;

use JsonException;

use function max;
use function mb_strtolower;
use function microtime;

use PDO;
use PDOStatement;

use function preg_match;
use function preg_replace;

use RuntimeException;

use function sprintf;
use function str_ends_with;
use function str_replace;
use function strtolower;

use Throwable;

use function trim;

/**
 * @phpstan-type CanonicalAddress array{city: string, street: string, housenumber: string, postcode: string}
 * @phpstan-type AliasAddress array{rank: int, city: string, street: string, housenumber: string, postcode: string}
 * @phpstan-type CanonicalRow array{object_id: int, object_guid: string|null, hierarchy_type: string, address_string: string}
 * @phpstan-type AliasDatabaseRow array{
 *     object_id: int,
 *     object_guid: string|null,
 *     hierarchy_type: string,
 *     canonical_address_string: string,
 *     alias_string: string,
 *     alias_hash: string,
 *     alias_kind: string,
 *     source: string,
 *     source_object_type: string,
 *     source_object_id: int,
 *     source_address_rank: int,
 *     source_name: string|null,
 *     geo_lat: float|null,
 *     geo_lon: float|null,
 *     source_payload: string
 * }
 */
final class OsmAddressAliasImporter
{
    private const SOURCE = 'osm';
    private const ALIAS_KIND = 'osm_addr2';

    public function __construct(
        private readonly PDO $db,
        private readonly int $regionCode = 77,
    ) {}

    /**
     * @param null|callable(int $readRows, int $matchedRows, int $writtenRows, int $skippedRows): void $progress
     */
    public function import(
        string $source,
        int $batchSize = 1000,
        ?int $limit = null,
        bool $truncate = false,
        bool $dryRun = false,
        ?callable $progress = null,
    ): OsmAddressAliasImportResult {
        $source = trim($source);
        if ($source === '') {
            throw new RuntimeException('OSM alias source is required.');
        }

        if (!is_file($source)) {
            throw new RuntimeException(sprintf('OSM alias source file not found: %s', $source));
        }

        $batchSize = max(1, $batchSize);
        $startedAt = microtime(true);
        $readRows = 0;
        $matchedRows = 0;
        $writtenRows = 0;
        $skippedRows = 0;
        $batch = [];

        if ($truncate && !$dryRun) {
            $this->pdo()->exec('TRUNCATE TABLE address_aliases RESTART IDENTITY');
        }

        foreach ($this->items($source) as $item) {
            if ($limit !== null && $readRows >= $limit) {
                break;
            }

            $readRows++;
            $rows = $this->rowsForItem($item);
            if ($rows === []) {
                $skippedRows++;
                continue;
            }

            $matchedRows++;
            foreach ($rows as $row) {
                $batch[] = $row;
                if (count($batch) < $batchSize) {
                    continue;
                }

                $writtenRows += $dryRun ? count($batch) : $this->writeBatch($batch);
                $batch = [];

                if ($progress !== null) {
                    $progress($readRows, $matchedRows, $writtenRows, $skippedRows);
                }
            }
        }

        if ($batch !== []) {
            $writtenRows += $dryRun ? count($batch) : $this->writeBatch($batch);
            if ($progress !== null) {
                $progress($readRows, $matchedRows, $writtenRows, $skippedRows);
            }
        }

        return new OsmAddressAliasImportResult(
            source: $source,
            readRows: $readRows,
            matchedRows: $matchedRows,
            writtenRows: $writtenRows,
            skippedRows: $skippedRows,
            duration: microtime(true) - $startedAt,
            dryRun: $dryRun,
        );
    }

    /**
     * @return iterable<array<string, mixed>>
     */
    private function items(string $source): iterable
    {
        if (str_ends_with(strtolower($source), '.gz')) {
            $handle = gzopen($source, 'rb');
            if ($handle === false) {
                throw new RuntimeException(sprintf('Unable to open OSM alias JSONL.GZ file: %s', $source));
            }

            try {
                while (($line = gzgets($handle)) !== false) {
                    $item = $this->decodeLine($line, $source);
                    if ($item !== null) {
                        yield $item;
                    }
                }
            } finally {
                gzclose($handle);
            }

            return;
        }

        $handle = fopen($source, 'rb');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open OSM alias JSONL file: %s', $source));
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $item = $this->decodeLine($line, $source);
                if ($item !== null) {
                    yield $item;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeLine(string $line, string $source): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }

        try {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('Invalid JSONL in %s: %s', $source, $e->getMessage()), 0, $e);
        }

        if (!is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $item
     * @return list<AliasDatabaseRow>
     */
    private function rowsForItem(array $item): array
    {
        $sourceObjectType = $this->sourceObjectType($this->stringField($item, 'source_object_type'));
        $sourceObjectId = $this->intField($item, 'source_object_id');
        if ($sourceObjectId === null) {
            return [];
        }

        $canonical = $this->canonicalAddress($item);
        if ($canonical['street'] === '' || $canonical['housenumber'] === '') {
            return [];
        }

        $canonicalRows = $this->matchCanonicalRows($canonical);
        if ($canonicalRows === []) {
            return [];
        }

        $aliases = $this->aliasAddresses($item, $canonical);
        if ($aliases === []) {
            return [];
        }

        $sourceName = $this->sourceName($item);
        $geoLat = $this->floatField($item, 'geo_lat');
        $geoLon = $this->floatField($item, 'geo_lon');
        /** @var list<AliasDatabaseRow> $rows */
        $rows = [];

        foreach ($aliases as $alias) {
            foreach ($this->aliasStrings($alias) as $aliasString) {
                foreach ($canonicalRows as $canonicalRow) {
                    $rows[] = [
                        'object_id' => $canonicalRow['object_id'],
                        'object_guid' => $canonicalRow['object_guid'],
                        'hierarchy_type' => $canonicalRow['hierarchy_type'],
                        'canonical_address_string' => $canonicalRow['address_string'],
                        'alias_string' => $aliasString,
                        'alias_hash' => hash('md5', mb_strtolower($aliasString, 'UTF-8')),
                        'alias_kind' => self::ALIAS_KIND,
                        'source' => self::SOURCE,
                        'source_object_type' => $sourceObjectType,
                        'source_object_id' => $sourceObjectId,
                        'source_address_rank' => $alias['rank'],
                        'source_name' => $sourceName,
                        'geo_lat' => $geoLat,
                        'geo_lon' => $geoLon,
                        'source_payload' => json_encode([
                            'canonical' => $canonical,
                            'alias' => $alias,
                            'tags' => is_array($item['tags'] ?? null) ? $item['tags'] : [],
                        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    ];
                }
            }
        }

        return $this->uniqueRows($rows);
    }

    /**
     * @param array<string, mixed> $item
     * @return CanonicalAddress
     */
    private function canonicalAddress(array $item): array
    {
        $canonical = is_array($item['canonical'] ?? null) ? $item['canonical'] : [];
        $tags = is_array($item['tags'] ?? null) ? $item['tags'] : [];
        /** @var array<string, mixed> $canonical */
        /** @var array<string, mixed> $tags */

        return [
            'city' => $this->firstString($canonical, $tags, 'city', 'addr:city'),
            'street' => $this->firstString($canonical, $tags, 'street', 'addr:street'),
            'housenumber' => $this->firstString($canonical, $tags, 'housenumber', 'addr:housenumber'),
            'postcode' => $this->firstString($canonical, $tags, 'postcode', 'addr:postcode'),
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @param CanonicalAddress $canonical
     * @return list<AliasAddress>
     */
    private function aliasAddresses(array $item, array $canonical): array
    {
        /** @var list<AliasAddress> $aliases */
        $aliases = [];

        if (is_array($item['aliases'] ?? null)) {
            foreach ($item['aliases'] as $alias) {
                if (!is_array($alias)) {
                    continue;
                }

                /** @var array<string, mixed> $alias */
                $rawRank = $alias['rank'] ?? 2;
                $rank = is_numeric($rawRank) ? (int) $rawRank : 2;
                $normalized = $this->normalizeAliasAddress($alias, $canonical, $rank);
                if ($normalized !== null) {
                    $aliases[] = $normalized;
                }
            }
        }

        $tags = is_array($item['tags'] ?? null) ? $item['tags'] : [];
        /** @var array<string, mixed> $tags */
        for ($rank = 2; $rank <= 9; $rank++) {
            $prefix = 'addr' . $rank . ':';
            $street = $this->stringField($tags, $prefix . 'street');
            $house = $this->stringField($tags, $prefix . 'housenumber');
            if ($street === '' || $house === '') {
                continue;
            }

            $normalized = $this->normalizeAliasAddress([
                'rank' => $rank,
                'city' => $this->stringField($tags, $prefix . 'city'),
                'street' => $street,
                'housenumber' => $house,
                'postcode' => $this->stringField($tags, $prefix . 'postcode'),
            ], $canonical, $rank);
            if ($normalized !== null) {
                $aliases[] = $normalized;
            }
        }

        return $this->uniqueAliases($aliases);
    }

    /**
     * @param array<string, mixed> $alias
     * @param CanonicalAddress $canonical
     * @return AliasAddress|null
     */
    private function normalizeAliasAddress(array $alias, array $canonical, int $rank): ?array
    {
        $street = $this->stringField($alias, 'street');
        $house = $this->stringField($alias, 'housenumber');
        if ($street === '' || $house === '') {
            return null;
        }

        $city = $this->stringField($alias, 'city');
        if ($city === '') {
            $city = $canonical['city'];
        }

        $postcode = $this->stringField($alias, 'postcode');
        if ($postcode === '') {
            $postcode = $canonical['postcode'];
        }

        return [
            'rank' => max(1, $rank),
            'city' => $this->normalizeText($city),
            'street' => $this->normalizeText($street),
            'housenumber' => $this->normalizeText($house),
            'postcode' => $this->normalizeText($postcode),
        ];
    }

    /**
     * @param list<AliasAddress> $aliases
     * @return list<AliasAddress>
     */
    private function uniqueAliases(array $aliases): array
    {
        $result = [];
        $seen = [];

        foreach ($aliases as $alias) {
            $key = mb_strtolower(implode('|', [
                (string) $alias['rank'],
                $alias['city'],
                $alias['street'],
                $alias['housenumber'],
                $alias['postcode'],
            ]), 'UTF-8');
            if (array_key_exists($key, $seen)) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $alias;
        }

        return $result;
    }

    /**
     * @param AliasAddress $alias
     * @return list<string>
     */
    private function aliasStrings(array $alias): array
    {
        $city = $this->normalizeText($alias['city']);
        $street = $this->normalizeText($alias['street']);
        $house = $this->normalizeText($alias['housenumber']);

        if ($street === '' || $house === '') {
            return [];
        }

        $strings = [];
        if ($city !== '') {
            $strings[] = sprintf('%s, %s, %s', $city, $street, $house);
            $strings[] = sprintf('%s, %s, дом %s', $city, $street, $house);
            $strings[] = sprintf('%s %s %s', $city, $street, $house);

            if (preg_match('/^(?:г\.?|город)\s+/iu', $city) !== 1) {
                $strings[] = sprintf('город %s, %s, дом %s', $city, $street, $house);
            }
        } else {
            $strings[] = sprintf('%s, %s', $street, $house);
            $strings[] = sprintf('%s, дом %s', $street, $house);
            $strings[] = sprintf('%s %s', $street, $house);
        }

        $result = [];
        $seen = [];
        foreach ($strings as $string) {
            $string = $this->normalizeAddressString($string);
            $key = mb_strtolower($string, 'UTF-8');
            if ($string === '' || array_key_exists($key, $seen)) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $string;
        }

        return $result;
    }

    /**
     * @param CanonicalAddress $address
     * @return list<CanonicalRow>
     */
    private function matchCanonicalRows(array $address): array
    {
        $street = $this->streetCore($address['street']);
        $house = $this->houseMain($address['housenumber']);
        if ($street === '' || $house === '') {
            return [];
        }

        $building = $this->houseBuilding($address['housenumber']);
        $buildingOrder = '';
        if ($building !== '') {
            $buildingOrder = "CASE WHEN s.address_string ~* :building_regex THEN 0 ELSE 1 END,";
        }

        $statement = $this->prepare(sprintf(
            <<<'SQL'
SELECT s.object_id
FROM address_search s
WHERE s.region_code = :region_code
  AND s.level = 10
  AND s.address_string ILIKE :street ESCAPE '\'
  AND s.address_string ILIKE :house ESCAPE '\'
ORDER BY
  %s
  CASE WHEN s.hierarchy_type = 'adm' THEN 0 ELSE 1 END,
  length(s.address_string) ASC,
  s.object_id ASC
LIMIT 1
SQL,
            $buildingOrder,
        ));
        $statement->bindValue(':region_code', $this->regionCode, PDO::PARAM_INT);
        $statement->bindValue(':street', '%' . $this->escapeLike($street) . '%');
        $statement->bindValue(':house', '%' . $this->escapeLike($house) . '%');
        if ($building !== '') {
            $statement->bindValue(':building_regex', '(стр\.?|строение|с)[[:space:]]*' . $this->regexQuote($building));
        }
        $statement->execute();
        $objectId = $statement->fetchColumn();
        if (!is_numeric($objectId)) {
            return [];
        }

        $statement = $this->prepare(<<<'SQL'
SELECT
    object_id,
    object_guid::text AS object_guid,
    address_string,
    hierarchy_type
FROM address_search
WHERE region_code = :region_code
  AND object_id = :object_id
ORDER BY CASE WHEN hierarchy_type = 'adm' THEN 0 ELSE 1 END, address_string ASC
SQL);
        $statement->bindValue(':region_code', $this->regionCode, PDO::PARAM_INT);
        $statement->bindValue(':object_id', (int) $objectId, PDO::PARAM_INT);
        $statement->execute();

        /** @var list<CanonicalRow> $rows */
        $rows = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $rowObjectId = $row['object_id'] ?? null;
            $objectGuid = $row['object_guid'] ?? null;
            $addressString = $row['address_string'] ?? null;
            $hierarchyType = $row['hierarchy_type'] ?? null;
            if (
                !is_numeric($rowObjectId)
                || ($objectGuid !== null && !is_string($objectGuid))
                || !is_string($addressString)
                || !is_string($hierarchyType)
            ) {
                continue;
            }

            $rows[] = [
                'object_id' => (int) $rowObjectId,
                'object_guid' => $objectGuid,
                'address_string' => $addressString,
                'hierarchy_type' => $hierarchyType,
            ];
        }

        return $rows;
    }

    private function streetCore(string $street): string
    {
        $street = $this->normalizeText($street);
        $street = (string) preg_replace('/[.,]/u', ' ', $street);
        $street = (string) preg_replace(
            '/\b(?:улица|ул|проспект|пр-т|пр|переулок|пер|площадь|пл|шоссе|ш|бульвар|бул|наб(?:ережная)?|проезд|аллея|тупик)\b\.?/iu',
            ' ',
            $street,
        );

        return $this->normalizeText($street);
    }

    private function houseMain(string $house): string
    {
        if (preg_match('/^\s*(\d+(?:[\/-]\d+)?(?:[а-яa-z])?)/iu', $house, $match) !== 1) {
            return $this->normalizeText($house);
        }

        return $this->normalizeText($match[1]);
    }

    private function houseBuilding(string $house): string
    {
        if (preg_match('/(?:^|\s)(?:с|стр|строение)\.?\s*(\d+[а-яa-z]?)/iu', $house, $match) !== 1) {
            return '';
        }

        return $this->normalizeText($match[1]);
    }

    /**
     * @param list<AliasDatabaseRow> $rows
     * @return list<AliasDatabaseRow>
     */
    private function uniqueRows(array $rows): array
    {
        $result = [];
        $seen = [];

        foreach ($rows as $row) {
            $key = implode('|', [
                $row['source'],
                $row['source_object_type'],
                (string) $row['source_object_id'],
                (string) $row['source_address_rank'],
                $row['hierarchy_type'],
                $row['alias_hash'],
            ]);
            if (array_key_exists($key, $seen)) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $row;
        }

        return $result;
    }

    /**
     * @param list<AliasDatabaseRow> $rows
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
        } catch (Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }

        return count($rows);
    }

    private function insertStatement(PDO $pdo): PDOStatement
    {
        $statement = $pdo->prepare(<<<'SQL'
INSERT INTO address_aliases (
    object_id,
    object_guid,
    hierarchy_type,
    canonical_address_string,
    alias_string,
    alias_hash,
    alias_kind,
    source,
    source_object_type,
    source_object_id,
    source_address_rank,
    source_name,
    geo_lat,
    geo_lon,
    source_payload
) VALUES (
    :object_id,
    CAST(:object_guid AS uuid),
    :hierarchy_type,
    :canonical_address_string,
    :alias_string,
    :alias_hash,
    :alias_kind,
    :source,
    :source_object_type,
    :source_object_id,
    :source_address_rank,
    :source_name,
    :geo_lat,
    :geo_lon,
    CAST(:source_payload AS jsonb)
)
ON CONFLICT (
    source,
    source_object_type,
    source_object_id,
    source_address_rank,
    hierarchy_type,
    alias_hash
) DO UPDATE SET
    object_id = EXCLUDED.object_id,
    object_guid = EXCLUDED.object_guid,
    canonical_address_string = EXCLUDED.canonical_address_string,
    alias_string = EXCLUDED.alias_string,
    alias_kind = EXCLUDED.alias_kind,
    source_name = EXCLUDED.source_name,
    geo_lat = EXCLUDED.geo_lat,
    geo_lon = EXCLUDED.geo_lon,
    source_payload = EXCLUDED.source_payload,
    updated_at = CURRENT_TIMESTAMP
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare OSM alias upsert statement.');
        }

        return $statement;
    }

    /**
     * @param AliasDatabaseRow $row
     */
    private function bindRow(PDOStatement $statement, array $row): void
    {
        foreach ($row as $name => $value) {
            $parameter = ':' . $name;
            if ($value === null || $value === '') {
                $statement->bindValue($parameter, null, PDO::PARAM_NULL);
                continue;
            }

            if (is_int($value)) {
                $statement->bindValue($parameter, $value, PDO::PARAM_INT);
                continue;
            }

            $statement->bindValue($parameter, $value, PDO::PARAM_STR);
        }
    }

    /**
     * @param array<string, mixed> $primary
     * @param array<string, mixed> $fallback
     */
    private function firstString(array $primary, array $fallback, string $primaryKey, string $fallbackKey): string
    {
        $value = $this->stringField($primary, $primaryKey);

        return $value !== '' ? $value : $this->stringField($fallback, $fallbackKey);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function stringField(array $item, string $name): string
    {
        if (array_key_exists($name, $item)) {
            $value = $item[$name];
        } else {
            $value = null;
            $lowerName = strtolower($name);
            foreach ($item as $key => $candidate) {
                if (is_string($key) && strtolower($key) === $lowerName) {
                    $value = $candidate;
                    break;
                }
            }
        }

        if ($value === null || !is_scalar($value)) {
            return '';
        }

        return $this->normalizeText((string) $value);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function intField(array $item, string $name): ?int
    {
        $value = $this->stringField($item, $name);
        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function floatField(array $item, string $name): ?float
    {
        $value = $this->stringField($item, $name);
        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        $value = (float) $value;
        if ($name === 'geo_lat' && ($value < -90.0 || $value > 90.0)) {
            return null;
        }

        if ($name === 'geo_lon' && ($value < -180.0 || $value > 180.0)) {
            return null;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function sourceName(array $item): ?string
    {
        $sourceName = $this->stringField($item, 'source_name');
        if ($sourceName !== '') {
            return $sourceName;
        }

        $tags = is_array($item['tags'] ?? null) ? $item['tags'] : [];
        /** @var array<string, mixed> $tags */
        $name = $this->stringField($tags, 'name');

        return $name !== '' ? $name : null;
    }

    private function sourceObjectType(string $type): string
    {
        $type = strtolower(trim($type));

        return in_array($type, ['node', 'way', 'relation'], true) ? $type : 'unknown';
    }

    private function normalizeAddressString(string $value): string
    {
        $value = $this->normalizeText($value);
        $value = (string) preg_replace('/\s+,/u', ',', $value);
        $value = (string) preg_replace('/,\s*/u', ', ', $value);

        return $this->normalizeText($value);
    }

    private function normalizeText(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function regexQuote(string $value): string
    {
        return str_replace(
            ['\\', '.', '+', '*', '?', '[', '^', ']', '$', '(', ')', '{', '}', '=', '!', '<', '>', '|', ':', '-'],
            ['\\\\', '\.', '\+', '\*', '\?', '\[', '\^', '\]', '\$', '\(', '\)', '\{', '\}', '\=', '\!', '\<', '\>', '\|', '\:', '\-'],
            $value,
        );
    }

    private function pdo(): PDO
    {
        return $this->db;
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare OSM alias matching statement.');
        }

        return $statement;
    }
}
