<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\Postgres;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Postgres\PostgresConnectionPool;
use App\Module\AddressRegistry\Capability\AddressDocument;
use App\Module\AddressRegistry\Capability\FindAddressById\FindAddressByIdPort;
use App\Module\AddressRegistry\Capability\FindAddressById\FindAddressByIdUnavailable;
use RuntimeException;
use Throwable;

final readonly class PostgresFindAddressByIdReader implements FindAddressByIdPort
{
    private const SELECT = <<<'SQL'
SELECT
    s.object_id,
    s.object_guid::text AS object_guid,
    s.address_string,
    s.hierarchy_type,
    s.level,
    s.postal_code,
    s.okato,
    s.oktmo,
    s.geo_lat,
    s.geo_lon,
    s.address_source,
    s.address_source_id,
    s.datamos_global_id,
    s.datamos_unom,
    s.datamos_nreg,
    s.datamos_adm_area,
    s.datamos_district,
    s.datamos_kad_n,
    s.datamos_kad_zu,
    aliases.address_alias_count
FROM address_search s
LEFT JOIN LATERAL (
    SELECT count(*)::integer AS address_alias_count
    FROM address_aliases a
    WHERE a.object_id = s.object_id
      AND a.hierarchy_type = s.hierarchy_type
) aliases ON true
WHERE %s
ORDER BY s.hierarchy_type ASC, s.level ASC, s.address_string ASC
LIMIT :limit
SQL;

    public function __construct(private PostgresConnectionPool $postgres) {}

    public function find(string $id, int $limit, Cancellation $cancellation): array
    {
        $cancellation->throwIfRequested();

        if (ctype_digit($id)) {
            $condition = <<<'SQL'
s.object_id = :object_id
OR s.datamos_global_id = :object_id
OR s.datamos_unom = :object_id
OR s.datamos_nreg = :object_id
SQL;
            $parameters = ['object_id' => (int) $id, 'limit' => $limit];
        } elseif (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $id) === 1) {
            $condition = 's.object_guid = CAST(:object_guid AS uuid)';
            $parameters = ['object_guid' => $id, 'limit' => $limit];
        } else {
            return [];
        }

        try {
            $result = $this->postgres->execute(sprintf(self::SELECT, $condition), $parameters);
            $documents = [];

            while (($row = $result->fetchRow()) !== null) {
                $cancellation->throwIfRequested();
                $documents[] = $this->document($row);
            }

            $cancellation->throwIfRequested();

            return $documents;
        } catch (CancelledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new FindAddressByIdUnavailable($exception);
        }
    }

    /** @param array<array-key, mixed> $row */
    private function document(array $row): AddressDocument
    {
        return new AddressDocument(
            objectId: $this->integer($row, 'object_id'),
            objectGuid: $this->nullableString($row, 'object_guid'),
            address: $this->string($row, 'address_string'),
            hierarchy: $this->string($row, 'hierarchy_type'),
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
        );
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
    private function nullableString(array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;

        return $value === null ? null : $this->string($row, $field);
    }

    /** @param array<array-key, mixed> $row */
    private function integer(array $row, string $field): int
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
    private function nullableInteger(array $row, string $field): ?int
    {
        return ($row[$field] ?? null) === null ? null : $this->integer($row, $field);
    }

    /** @param array<array-key, mixed> $row */
    private function nullableFloat(array $row, string $field): ?float
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
