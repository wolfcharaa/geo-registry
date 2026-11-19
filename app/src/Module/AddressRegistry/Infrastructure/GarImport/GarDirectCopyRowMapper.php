<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use PgSql\Connection;
use PgSql\Result;

final class GarDirectCopyRowMapper
{
    /** @var array<array-key, string>|null */
    private ?array $addressTypeIds = null;

    public function __construct(private readonly Connection $connection) {}

    /**
     * @param list<?string> $values
     * @return list<?string>
     */
    public function map(GarXmlFileDescriptor $descriptor, array $values): array
    {
        $stageColumns = $descriptor->stageColumns();
        if (\count($stageColumns) !== \count($values)) {
            throw new GarImportFailed('GAR direct-copy row does not match its descriptor column count.');
        }
        if ($stageColumns === $descriptor->targetColumns()) {
            return $values;
        }
        if ($descriptor->key !== 'address-objects') {
            throw new GarImportFailed("GAR direct-copy has no row mapper for descriptor {$descriptor->key}.");
        }

        $stage = \array_combine($stageColumns, $values);
        $level = $stage['level_id'] ?? null;
        $typeName = $stage['type_name'] ?? null;
        if (!\is_string($level) || !\is_string($typeName)) {
            throw new GarImportFailed('GAR address object has no level/type pair for direct-copy.');
        }
        $addressTypeId = $this->addressTypeIds()[$this->addressTypeKey($level, $typeName)] ?? null;
        if ($addressTypeId === null) {
            throw new GarImportFailed("GAR address object type was not found for level {$level} and name {$typeName}.");
        }
        $stage['addr_type_id'] = $addressTypeId;

        $target = [];
        foreach ($descriptor->targetColumns() as $column) {
            if (!\array_key_exists($column, $stage)) {
                throw new GarImportFailed("GAR direct-copy target column has no mapped value: {$column}");
            }
            $target[] = $stage[$column];
        }

        return $target;
    }

    /** @return array<array-key, string> */
    private function addressTypeIds(): array
    {
        if ($this->addressTypeIds !== null) {
            return $this->addressTypeIds;
        }
        $result = \pg_query(
            $this->connection,
            'SELECT id, level, short_name FROM address_object_types ORDER BY id',
        );
        if (!$result instanceof Result) {
            throw new GarImportFailed('Unable to load GAR address object types for direct-copy.');
        }

        $ids = self::emptyStringMap();
        while (($row = \pg_fetch_assoc($result)) !== false) {
            $id = $row['id'] ?? null;
            $level = $row['level'] ?? null;
            $shortName = $row['short_name'] ?? null;
            if (!\is_string($id) || !\is_string($level) || !\is_string($shortName)) {
                throw new GarImportFailed('PostgreSQL returned an invalid GAR address object type row.');
            }
            $key = $this->addressTypeKey($level, $shortName);
            $ids[$key] ??= $id;
        }
        $this->addressTypeIds = $ids;

        return $this->addressTypeIds;
    }

    private function addressTypeKey(string $level, string $shortName): string
    {
        return '#' . $level . "\0" . $shortName;
    }

    /** @return array<array-key, string> */
    private static function emptyStringMap(): array
    {
        return [];
    }
}
