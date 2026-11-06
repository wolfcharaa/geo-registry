<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\OpenSearch\Infrastructure;

use Amp\Cancellation;
use App\Infrastructure\OpenSearch\OpenSearchException;
use App\Module\AddressRegistry\Capability\AddressAlias;
use App\Module\AddressRegistry\Capability\AddressDocument;
use App\Module\AddressRegistry\Capability\AddressHierarchy;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressMatch;

final class OpenSearchAddressSearchResultMapper
{
    /**
     * @param array<string, mixed> $response
     * @return list<SearchAddressMatch>
     */
    public function map(array $response, Cancellation $cancellation): array
    {
        $hitsEnvelope = self::object($response['hits'] ?? null, 'hits');
        $hits = $hitsEnvelope['hits'] ?? null;
        if (!is_array($hits) || !array_is_list($hits)) {
            throw new OpenSearchException('OpenSearch address hits must be a list.');
        }

        $matches = [];
        foreach ($hits as $hit) {
            $cancellation->throwIfRequested();
            $hit = self::object($hit, 'hit');
            $source = self::object($hit['_source'] ?? null, '_source');
            $aliases = $this->aliases($source['aliases'] ?? []);
            $matches[] = new SearchAddressMatch(
                document: new AddressDocument(
                    objectId: self::integer($source, 'object_id'),
                    objectGuid: self::nullableString($source, 'object_guid'),
                    address: self::string($source, 'address'),
                    hierarchy: AddressHierarchy::from(self::string($source, 'hierarchy_type')),
                    level: self::integer($source, 'level'),
                    postalCode: self::nullableString($source, 'postal_code'),
                    okato: self::nullableString($source, 'okato'),
                    oktmo: self::nullableString($source, 'oktmo'),
                    latitude: self::nullableFloat($source, 'geo_lat'),
                    longitude: self::nullableFloat($source, 'geo_lon'),
                    source: self::nullableString($source, 'source'),
                    sourceId: self::nullableIdentifier($source, 'source_id'),
                    dataMosGlobalId: self::nullableInteger($source, 'datamos_global_id'),
                    dataMosUnom: self::nullableInteger($source, 'datamos_unom'),
                    dataMosNreg: self::nullableInteger($source, 'datamos_nreg'),
                    dataMosAdministrativeArea: self::nullableString($source, 'datamos_adm_area'),
                    dataMosDistrict: self::nullableString($source, 'datamos_district'),
                    cadastralNumbers: self::stringList($source['cadastral_numbers'] ?? []),
                    landCadastralNumbers: self::stringList($source['land_cadastral_numbers'] ?? []),
                    aliasCount: self::optionalInteger($source, 'alias_count', count($aliases)),
                    regionCode: self::integer($source, 'region_code'),
                ),
                score: self::score($hit['_score'] ?? null),
                aliases: $aliases,
                matchedAlias: self::nullableString($source, 'matched_alias'),
                backend: 'opensearch',
            );
        }
        $cancellation->throwIfRequested();

        return $matches;
    }

    /** @return list<AddressAlias> */
    private function aliases(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new OpenSearchException('OpenSearch address aliases must be a list.');
        }

        $aliases = [];
        foreach ($value as $alias) {
            $alias = self::object($alias, 'alias');
            $aliases[] = new AddressAlias(
                value: self::string($alias, 'value'),
                kind: self::string($alias, 'kind'),
                source: self::string($alias, 'source'),
                sourceObjectType: self::string($alias, 'source_object_type'),
                sourceObjectId: self::integer($alias, 'source_object_id'),
                rank: self::integer($alias, 'rank'),
            );
        }

        return $aliases;
    }

    /** @return array<array-key, mixed> */
    private static function object(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new OpenSearchException("OpenSearch field `{$field}` must be an object.");
        }

        return $value;
    }

    /** @param array<array-key, mixed> $values */
    private static function string(array $values, string $field): string
    {
        $value = $values[$field] ?? null;
        if (!is_string($value)) {
            throw new OpenSearchException("OpenSearch field `{$field}` must be a string.");
        }

        return $value;
    }

    /** @param array<array-key, mixed> $values */
    private static function nullableString(array $values, string $field): ?string
    {
        return ($values[$field] ?? null) === null ? null : self::string($values, $field);
    }

    /** @param array<array-key, mixed> $values */
    private static function integer(array $values, string $field): int
    {
        $value = $values[$field] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            return (int) $value;
        }

        throw new OpenSearchException("OpenSearch field `{$field}` must be an integer.");
    }

    /** @param array<array-key, mixed> $values */
    private static function nullableInteger(array $values, string $field): ?int
    {
        return ($values[$field] ?? null) === null ? null : self::integer($values, $field);
    }

    /** @param array<array-key, mixed> $values */
    private static function optionalInteger(array $values, string $field, int $default): int
    {
        return array_key_exists($field, $values) ? self::integer($values, $field) : $default;
    }

    /** @param array<array-key, mixed> $values */
    private static function nullableFloat(array $values, string $field): ?float
    {
        $value = $values[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        throw new OpenSearchException("OpenSearch field `{$field}` must be numeric.");
    }

    /** @param array<array-key, mixed> $values */
    private static function nullableIdentifier(array $values, string $field): ?string
    {
        $value = $values[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value)) {
            return (string) $value;
        }

        throw new OpenSearchException("OpenSearch field `{$field}` must be a string or integer.");
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new OpenSearchException('OpenSearch string collection must be a list.');
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new OpenSearchException('OpenSearch string collection must contain only strings.');
            }
        }

        return $value;
    }

    private static function score(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        throw new OpenSearchException('OpenSearch hit score must be numeric.');
    }
}
