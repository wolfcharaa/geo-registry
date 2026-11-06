<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\Postgres;

use App\Module\AddressRegistry\Capability\AddressDocument;
use App\Module\AddressRegistry\Capability\AddressHierarchy;
use RuntimeException;

final readonly class PostgresAddressDocumentHydrator
{
    /** @param array<array-key, mixed> $row */
    public function hydrate(array $row): AddressDocument
    {
        return new AddressDocument(
            objectId: $this->integer($row, 'object_id'),
            objectGuid: $this->nullableString($row, 'object_guid'),
            address: $this->string($row, 'address_string'),
            hierarchy: AddressHierarchy::from($this->string($row, 'hierarchy_type')),
            level: $this->integer($row, 'level'),
            postalCode: $this->nullableString($row, 'postal_code'),
            okato: $this->nullableString($row, 'okato'),
            oktmo: $this->nullableString($row, 'oktmo'),
            latitude: $this->nullableFloat($row, 'geo_lat'),
            longitude: $this->nullableFloat($row, 'geo_lon'),
            source: $this->nullableString($row, 'address_source'),
            sourceId: $this->nullableString($row, 'address_source_id'),
            dataMosGlobalId: $this->nullableInteger($row, 'datamos_global_id'),
            dataMosUnom: $this->nullableInteger($row, 'datamos_unom'),
            dataMosNreg: $this->nullableInteger($row, 'datamos_nreg'),
            dataMosAdministrativeArea: $this->nullableString($row, 'datamos_adm_area'),
            dataMosDistrict: $this->nullableString($row, 'datamos_district'),
            cadastralNumbers: $this->stringList($row['datamos_kad_n'] ?? null),
            landCadastralNumbers: $this->stringList($row['datamos_kad_zu'] ?? null),
            aliasCount: $this->integer($row, 'address_alias_count'),
            regionCode: $this->integer($row, 'region_code'),
        );
    }

    /** @param array<array-key, mixed> $row */
    public function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            return (int) $value;
        }

        throw new RuntimeException("PostgreSQL field `{$field}` must be an integer.");
    }

    /** @param array<array-key, mixed> $row */
    public function nullableFloat(array $row, string $field): ?float
    {
        $value = $row[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        throw new RuntimeException("PostgreSQL field `{$field}` must be numeric.");
    }

    /** @param array<array-key, mixed> $row */
    public function nullableString(array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;

        return $value === null ? null : $this->string($row, $field);
    }

    /** @param array<array-key, mixed> $row */
    private function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException("PostgreSQL field `{$field}` must be a string.");
        }

        return $value;
    }

    /** @param array<array-key, mixed> $row */
    private function nullableInteger(array $row, string $field): ?int
    {
        return ($row[$field] ?? null) === null ? null : $this->integer($row, $field);
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        }
        if (!is_array($value)) {
            return [];
        }

        $strings = [];
        $this->collectStrings($value, $strings);

        return array_values($strings);
    }

    /** @param array<string, string> $strings */
    private function collectStrings(mixed $value, array &$strings): void
    {
        if (is_string($value)) {
            $value = trim($value);
            if ($value !== '') {
                $strings['value:' . $value] = $value;
            }

            return;
        }
        if (!is_array($value)) {
            return;
        }

        foreach ($value as $item) {
            $this->collectStrings($item, $strings);
        }
    }
}
