<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\Postgres;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Postgres\PostgresConnectionPool;
use App\Module\AddressRegistry\Capability\AddressHierarchy;
use App\Module\AddressRegistry\Capability\ProgressiveAddressSuggestion;
use App\Module\AddressRegistry\Capability\ResolveSuggestionContext\ResolveSuggestionContextPort;
use App\Module\AddressRegistry\Capability\SuggestAddressUnavailable;
use App\Module\AddressRegistry\Capability\SuggestAdministrativeArea\SuggestAdministrativeAreaPort;
use App\Module\AddressRegistry\Capability\SuggestionContext;
use App\Module\AddressRegistry\Capability\SuggestionCriteria;
use App\Module\AddressRegistry\Capability\SuggestionDistrict;
use App\Module\AddressRegistry\Capability\SuggestThoroughfare\SuggestThoroughfarePort;
use RuntimeException;
use Throwable;

final readonly class PostgresAddressSuggestionReader implements
    ResolveSuggestionContextPort,
    SuggestAdministrativeAreaPort,
    SuggestThoroughfarePort
{
    private const RESOLVE_CONTEXT = <<<'SQL'
SELECT
    s.object_id,
    s.region_code,
    s.level,
    district.object_id AS district_object_id,
    district.name AS district_name
FROM address_search s
JOIN LATERAL (
    SELECT hierarchy.path_arr
    FROM %s hierarchy
    WHERE hierarchy.object_id = s.object_id
      AND hierarchy.is_active = true
    ORDER BY hierarchy.id DESC
    LIMIT 1
) hierarchy ON true
LEFT JOIN LATERAL (
    SELECT
        district_object.reestr_object_id AS object_id,
        district_object.name
    FROM unnest(hierarchy.path_arr) WITH ORDINALITY AS path(object_id, position)
    JOIN reestr_objects district_registry
      ON district_registry.id = path.object_id
     AND district_registry.level_id = 2
     AND district_registry.is_active = true
    JOIN LATERAL (
        SELECT current_object.reestr_object_id, current_object.name
        FROM address_objects current_object
        WHERE current_object.reestr_object_id = district_registry.id
          AND current_object.is_actual = true
          AND current_object.is_active = true
        ORDER BY current_object.id DESC
        LIMIT 1
    ) district_object ON true
    ORDER BY path.position DESC
    LIMIT 1
) district ON true
WHERE s.object_id = :parent_id
  AND s.hierarchy_type = :hierarchy
  AND s.region_code IS NOT NULL
ORDER BY s.level DESC, s.id DESC
LIMIT 1
SQL;

    private const SUGGEST = <<<'SQL'
WITH regional_candidates AS MATERIALIZED (
    SELECT
        s.id,
        s.object_id,
        s.object_guid,
        s.hierarchy_type,
        s.level,
        s.region_code,
        current_object.name AS object_name,
        current_object.type_name,
        similarity(current_object.name, :query)::double precision AS suggestion_score
    FROM address_search s
    JOIN address_objects current_object
      ON current_object.reestr_object_id = s.object_id
     AND current_object.is_actual = true
     AND current_object.is_active = true
    WHERE s.hierarchy_type = :hierarchy
      AND %s
      AND %s
      AND NOT EXISTS (
          SELECT 1
          FROM address_objects newer_object
          WHERE newer_object.reestr_object_id = current_object.reestr_object_id
            AND newer_object.is_actual = true
            AND newer_object.is_active = true
            AND newer_object.id > current_object.id
      )
      AND (
          lower(current_object.name) = lower(:query)
          OR current_object.name ILIKE '%%' || :query || '%%'
          OR similarity(current_object.name, :query) >= 0.3
      )
)
SELECT
    candidate.object_id,
    candidate.object_guid::text AS object_guid,
    candidate.hierarchy_type,
    candidate.level,
    candidate.region_code,
    candidate.object_name,
    candidate.type_name,
    district.object_id AS district_object_id,
    district.name AS district_name,
    candidate.suggestion_score
FROM regional_candidates candidate
JOIN LATERAL (
    SELECT hierarchy.path_arr
    FROM %s hierarchy
    WHERE hierarchy.object_id = candidate.object_id
      AND hierarchy.is_active = true
    ORDER BY hierarchy.id DESC
    LIMIT 1
) hierarchy ON true
LEFT JOIN LATERAL (
    SELECT
        district_object.reestr_object_id AS object_id,
        district_object.name
    FROM unnest(hierarchy.path_arr) WITH ORDINALITY AS path(object_id, position)
    JOIN reestr_objects district_registry
      ON district_registry.id = path.object_id
     AND district_registry.level_id = 2
     AND district_registry.is_active = true
    JOIN LATERAL (
        SELECT active_district.reestr_object_id, active_district.name
        FROM address_objects active_district
        WHERE active_district.reestr_object_id = district_registry.id
          AND active_district.is_actual = true
          AND active_district.is_active = true
        ORDER BY active_district.id DESC
        LIMIT 1
    ) district_object ON true
    ORDER BY path.position DESC
    LIMIT 1
) district ON true
WHERE %s
ORDER BY
    (lower(candidate.object_name) = lower(:query)) DESC,
    candidate.suggestion_score DESC,
    candidate.level ASC,
    candidate.object_name ASC,
    candidate.object_id ASC
LIMIT :limit
SQL;

    public function __construct(
        private PostgresConnectionPool $postgres,
        private PostgresAddressDocumentHydrator $hydrator,
    ) {}

    public function resolve(
        int $parentId,
        AddressHierarchy $hierarchy,
        Cancellation $cancellation,
    ): ?SuggestionContext {
        $cancellation->throwIfRequested();

        try {
            $row = $this->postgres->execute(sprintf(
                self::RESOLVE_CONTEXT,
                self::hierarchyTable($hierarchy),
            ), [
                'parent_id' => $parentId,
                'hierarchy' => $hierarchy->value,
            ])->fetchRow();
            $cancellation->throwIfRequested();

            if ($row === null) {
                return null;
            }

            return new SuggestionContext(
                parentId: $this->hydrator->integer($row, 'object_id'),
                regionCode: $this->hydrator->integer($row, 'region_code'),
                hierarchy: $hierarchy,
                level: $this->hydrator->integer($row, 'level'),
                district: $this->district($row),
            );
        } catch (CancelledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new SuggestAddressUnavailable(previous: $exception);
        }
    }

    public function suggestAdministrativeAreas(
        SuggestionCriteria $criteria,
        Cancellation $cancellation,
    ): array {
        return $this->suggest($criteria, 'candidate.level BETWEEN 1 AND 6', $cancellation);
    }

    public function suggestThoroughfares(
        SuggestionCriteria $criteria,
        Cancellation $cancellation,
    ): array {
        return $this->suggest($criteria, 'candidate.level = 7', $cancellation);
    }

    /** @return list<ProgressiveAddressSuggestion> */
    private function suggest(
        SuggestionCriteria $criteria,
        string $levelCondition,
        Cancellation $cancellation,
    ): array {
        $cancellation->throwIfRequested();
        if ($criteria->query === '') {
            return [];
        }

        $parameters = [
            'query' => $criteria->query,
            'hierarchy' => $criteria->hierarchy->value,
            'limit' => $criteria->limit,
        ];
        $regionCondition = 'TRUE';
        $parentCondition = 'TRUE';
        if ($criteria->context !== null) {
            $regionCondition = 's.region_code = :region_code';
            $parentCondition = ':parent_id = ANY(hierarchy.path_arr) AND candidate.object_id <> :parent_id';
            $parameters['region_code'] = $criteria->context->regionCode;
            $parameters['parent_id'] = $criteria->context->parentId;
        }

        try {
            $result = $this->postgres->execute(sprintf(
                self::SUGGEST,
                $regionCondition,
                str_replace('candidate.', 's.', $levelCondition),
                self::hierarchyTable($criteria->hierarchy),
                $parentCondition,
            ), $parameters);
            $suggestions = [];

            while (($row = $result->fetchRow()) !== null) {
                $cancellation->throwIfRequested();
                $objectGuid = $this->hydrator->nullableString($row, 'object_guid');
                if ($objectGuid === null) {
                    throw new RuntimeException('PostgreSQL field `object_guid` must be a string.');
                }

                $suggestions[] = new ProgressiveAddressSuggestion(
                    objectId: $this->hydrator->integer($row, 'object_id'),
                    objectGuid: $objectGuid,
                    name: $this->string($row, 'object_name'),
                    typeName: $this->string($row, 'type_name'),
                    hierarchy: $criteria->hierarchy,
                    level: $this->hydrator->integer($row, 'level'),
                    regionCode: $this->hydrator->integer($row, 'region_code'),
                    district: $this->district($row),
                );
            }

            $cancellation->throwIfRequested();

            return $suggestions;
        } catch (CancelledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new SuggestAddressUnavailable(previous: $exception);
        }
    }

    /** @param array<array-key, mixed> $row */
    private function district(array $row): ?SuggestionDistrict
    {
        $objectId = $row['district_object_id'] ?? null;
        $name = $row['district_name'] ?? null;
        if ($objectId === null || $name === null) {
            return null;
        }

        return new SuggestionDistrict(
            objectId: $this->hydrator->integer($row, 'district_object_id'),
            name: $this->string($row, 'district_name'),
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

    private static function hierarchyTable(AddressHierarchy $hierarchy): string
    {
        return match ($hierarchy) {
            AddressHierarchy::Administrative => 'administrative_reestr_hierarchy',
            AddressHierarchy::Municipal => 'municipal_reestr_hierarchy',
        };
    }
}
