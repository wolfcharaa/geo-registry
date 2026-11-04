<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\AddressSearchRebuild;

use InvalidArgumentException;

final class AddressSearchRebuildSql
{
    public static function batch(string $hierarchy, string $targetTable): string
    {
        $selected = match ($hierarchy) {
            'adm' => <<<'SQL'
SELECT
    h.id,
    h.object_id,
    h.path_arr,
    h.region_code::smallint AS region_code,
    NULL::varchar(20) AS hierarchy_oktmo
FROM administrative_reestr_hierarchy h
WHERE h.is_active = true
  AND h.end_date > CURRENT_DATE
  AND h.region_code = $1
  AND h.id > $3
  AND h.id <= $4
SQL,
            'mun' => <<<'SQL'
SELECT
    h.id,
    h.object_id,
    h.path_arr,
    $1::smallint AS region_code,
    left(nullif(btrim(h.oktmo), ''), 20)::varchar(20) AS hierarchy_oktmo
FROM municipal_reestr_hierarchy h
WHERE h.is_active = true
  AND h.end_date > CURRENT_DATE
  AND h.id > $3
  AND h.id <= $4
  AND EXISTS (
      SELECT 1
      FROM administrative_reestr_hierarchy region_h
      WHERE region_h.object_id = h.object_id
        AND region_h.region_code = $1
        AND region_h.is_active = true
        AND region_h.end_date > CURRENT_DATE
  )
SQL,
            default => throw new InvalidArgumentException('Address search hierarchy must be "adm" or "mun".'),
        };

        return sprintf(
            <<<'SQL'
WITH selected AS MATERIALIZED (
%s
),
path_rows AS MATERIALIZED (
    SELECT
        selected.id AS source_hierarchy_id,
        selected.object_id AS selected_object_id,
        selected.path_arr,
        selected.region_code,
        selected.hierarchy_oktmo,
        path.object_id,
        path.position
    FROM selected
    JOIN LATERAL unnest(selected.path_arr) WITH ORDINALITY AS path(object_id, position) ON true
),
parts AS MATERIALIZED (
    SELECT
        path_rows.source_hierarchy_id,
        path_rows.selected_object_id,
        path_rows.path_arr,
        path_rows.region_code,
        path_rows.hierarchy_oktmo,
        path_rows.position,
        part.label,
        part.level
    FROM path_rows
    LEFT JOIN LATERAL (
        SELECT btrim(p.value) AS value
        FROM object_params p
        WHERE p.object_id = path_rows.object_id
          AND p.type_id = 16
          AND p.end_date > CURRENT_DATE
          AND btrim(p.value) <> ''
        ORDER BY p.update_date DESC, p.id DESC
        LIMIT 1
    ) official_name ON true
    JOIN LATERAL (
        SELECT candidate.label, candidate.level
        FROM (
            SELECT
                coalesce(
                    official_name.value,
                    nullif(concat_ws(' ', nullif(aot.name, ''), nullif(ao.name, '')), ''),
                    nullif(concat_ws(' ', nullif(ao.type_name, ''), nullif(ao.name, '')), '')
                ) AS label,
                ao.level_id::integer AS level,
                1 AS priority,
                ao.update_date,
                ao.id
            FROM address_objects ao
            LEFT JOIN address_object_types aot ON aot.id = ao.addr_type_id
            WHERE ao.reestr_object_id = path_rows.object_id
              AND ao.is_active = true
              AND ao.is_actual = true
              AND ao.end_date > CURRENT_DATE

            UNION ALL

            SELECT
                coalesce(
                    official_name.value,
                    nullif(
                        array_to_string(
                            array_remove(ARRAY[
                                CASE
                                    WHEN h.house_type_id IS NOT NULL AND nullif(btrim(h.house_num), '') IS NOT NULL
                                        THEN concat_ws(' ', coalesce(nullif(ht.short_name, ''), nullif(ht.name, ''), 'дом'), btrim(h.house_num))
                                    WHEN nullif(btrim(h.house_num), '') IS NOT NULL THEN btrim(h.house_num)
                                END,
                                CASE
                                    WHEN h.add_type1_id IS NOT NULL AND nullif(btrim(h.add_num1), '') IS NOT NULL
                                        THEN concat_ws(' ', coalesce(nullif(aht1.short_name, ''), nullif(aht1.name, ''), 'корпус'), btrim(h.add_num1))
                                    WHEN nullif(btrim(h.add_num1), '') IS NOT NULL THEN btrim(h.add_num1)
                                END,
                                CASE
                                    WHEN h.add_type2_id IS NOT NULL AND nullif(btrim(h.add_num2), '') IS NOT NULL
                                        THEN concat_ws(' ', coalesce(nullif(aht2.short_name, ''), nullif(aht2.name, ''), 'строение'), btrim(h.add_num2))
                                    WHEN nullif(btrim(h.add_num2), '') IS NOT NULL THEN btrim(h.add_num2)
                                END
                            ], NULL),
                            ' '
                        ),
                        ''
                    )
                ) AS label,
                10 AS level,
                2 AS priority,
                h.update_date,
                h.id
            FROM houses h
            LEFT JOIN house_types ht ON ht.id = h.house_type_id
            LEFT JOIN house_addr_types aht1 ON aht1.id = h.add_type1_id
            LEFT JOIN house_addr_types aht2 ON aht2.id = h.add_type2_id
            WHERE h.reestr_object_id = path_rows.object_id
              AND h.is_active = true
              AND h.is_actual = true
              AND h.end_date > CURRENT_DATE

            UNION ALL

            SELECT
                coalesce(
                    official_name.value,
                    nullif(concat_ws(
                        ' ',
                        coalesce(nullif(at.short_name, ''), nullif(at.name, ''), 'помещение'),
                        nullif(btrim(a.number), '')
                    ), '')
                ) AS label,
                11 AS level,
                3 AS priority,
                a.update_date,
                a.id
            FROM apartments a
            LEFT JOIN apartment_types at ON at.id = a.apart_type_id
            WHERE a.reestr_object_id = path_rows.object_id
              AND a.is_active = true
              AND a.is_actual = true
              AND a.end_date > CURRENT_DATE

            UNION ALL

            SELECT
                coalesce(
                    official_name.value,
                    nullif(concat_ws(
                        ' ',
                        coalesce(nullif(rt.short_name, ''), nullif(rt.name, ''), 'комната'),
                        nullif(btrim(r.number), '')
                    ), '')
                ) AS label,
                12 AS level,
                4 AS priority,
                r.update_date,
                r.id
            FROM rooms r
            LEFT JOIN room_types rt ON rt.id = r.room_type_id
            WHERE r.reestr_object_id = path_rows.object_id
              AND r.is_active = true
              AND r.is_actual = true
              AND r.end_date > CURRENT_DATE

            UNION ALL

            SELECT
                coalesce(
                    official_name.value,
                    nullif(concat_ws(' ', 'земельный участок', nullif(btrim(s.number), '')), '')
                ) AS label,
                9 AS level,
                5 AS priority,
                s.update_date,
                s.id
            FROM steads s
            WHERE s.object_id = path_rows.object_id
              AND s.is_active = true
              AND s.is_actual = true
              AND s.end_date > CURRENT_DATE

            UNION ALL

            SELECT
                coalesce(
                    official_name.value,
                    nullif(concat_ws(' ', 'машино-место', nullif(btrim(cp.number), '')), '')
                ) AS label,
                17 AS level,
                6 AS priority,
                cp.update_date,
                cp.id
            FROM car_places cp
            WHERE cp.reestr_object_id = path_rows.object_id
              AND cp.is_active = true
              AND cp.is_actual = true
              AND cp.end_date > CURRENT_DATE
        ) candidate
        WHERE candidate.label IS NOT NULL AND candidate.label <> ''
        ORDER BY candidate.priority DESC, candidate.update_date DESC, candidate.id DESC
        LIMIT 1
    ) part ON true
),
documents AS MATERIALIZED (
    SELECT
        parts.source_hierarchy_id,
        parts.selected_object_id AS object_id,
        parts.path_arr,
        parts.region_code,
        ro.object_guid,
        string_agg(parts.label, ', ' ORDER BY parts.position) AS address_string,
        (array_agg(parts.level ORDER BY parts.position DESC))[1] AS level,
        postal.value AS postal_code,
        okato.value AS okato,
        coalesce(oktmo.value, parts.hierarchy_oktmo) AS oktmo
    FROM parts
    LEFT JOIN reestr_objects ro ON ro.id = parts.selected_object_id
    LEFT JOIN LATERAL (
        SELECT left(btrim(p.value), 20)::varchar(20) AS value
        FROM unnest(parts.path_arr) WITH ORDINALITY AS path(object_id, position)
        JOIN object_params p ON p.object_id = path.object_id
        WHERE p.type_id = 5
          AND p.end_date > CURRENT_DATE
          AND btrim(p.value) <> ''
        ORDER BY
            CASE WHEN p.object_id = parts.selected_object_id THEN 0 ELSE 1 END,
            path.position DESC,
            p.update_date DESC,
            p.id DESC
        LIMIT 1
    ) postal ON true
    LEFT JOIN LATERAL (
        SELECT left(btrim(p.value), 20)::varchar(20) AS value
        FROM unnest(parts.path_arr) WITH ORDINALITY AS path(object_id, position)
        JOIN object_params p ON p.object_id = path.object_id
        WHERE p.type_id = 6
          AND p.end_date > CURRENT_DATE
          AND btrim(p.value) <> ''
        ORDER BY path.position DESC, p.update_date DESC, p.id DESC
        LIMIT 1
    ) okato ON true
    LEFT JOIN LATERAL (
        SELECT left(btrim(p.value), 20)::varchar(20) AS value
        FROM unnest(parts.path_arr) WITH ORDINALITY AS path(object_id, position)
        JOIN object_params p ON p.object_id = path.object_id
        WHERE p.type_id = 7
          AND p.end_date > CURRENT_DATE
          AND btrim(p.value) <> ''
        ORDER BY path.position DESC, p.update_date DESC, p.id DESC
        LIMIT 1
    ) oktmo ON true
    GROUP BY
        parts.source_hierarchy_id,
        parts.selected_object_id,
        parts.path_arr,
        parts.region_code,
        ro.object_guid,
        postal.value,
        okato.value,
        oktmo.value,
        parts.hierarchy_oktmo
),
deduplicated AS MATERIALIZED (
    SELECT DISTINCT ON (region_code, object_id)
        source_hierarchy_id,
        object_id,
        path_arr,
        region_code,
        object_guid,
        address_string,
        level,
        postal_code,
        okato,
        oktmo
    FROM documents
    WHERE address_string <> ''
    ORDER BY region_code, object_id, cardinality(path_arr) DESC, level DESC, address_string DESC, source_hierarchy_id DESC
)
INSERT INTO %s (
    region_code,
    object_id,
    object_guid,
    address_string,
    hierarchy_type,
    level,
    postal_code,
    okato,
    oktmo,
    geo_lat,
    geo_lon,
    geocoded_at,
    geocode_source,
    geocode_osm_type,
    geocode_osm_id,
    geocode_display_name,
    address_source,
    address_source_id,
    address_source_updated_at,
    datamos_global_id,
    datamos_unom,
    datamos_nreg,
    datamos_adm_area,
    datamos_district,
    datamos_kad_n,
    datamos_kad_zu,
    updated_at
)
SELECT
    document.region_code,
    document.object_id,
    document.object_guid,
    document.address_string,
    $2,
    document.level,
    document.postal_code,
    document.okato,
    document.oktmo,
    coalesce(existing.geo_lat, inherited.geo_lat),
    coalesce(existing.geo_lon, inherited.geo_lon),
    coalesce(existing.geocoded_at, inherited.geocoded_at),
    coalesce(existing.geocode_source, inherited.geocode_source),
    coalesce(existing.geocode_osm_type, inherited.geocode_osm_type),
    coalesce(existing.geocode_osm_id, inherited.geocode_osm_id),
    coalesce(existing.geocode_display_name, inherited.geocode_display_name),
    CASE
        WHEN coalesce(existing.address_source, '') LIKE '%%datamos%%' THEN existing.address_source
        WHEN coalesce(inherited.address_source, '') LIKE '%%datamos%%' THEN 'gar+datamos'
        ELSE coalesce(existing.address_source, 'gar')
    END,
    coalesce(existing.address_source_id, inherited.address_source_id),
    coalesce(existing.address_source_updated_at, inherited.address_source_updated_at),
    coalesce(existing.datamos_global_id, inherited.datamos_global_id),
    coalesce(existing.datamos_unom, inherited.datamos_unom),
    coalesce(existing.datamos_nreg, inherited.datamos_nreg),
    coalesce(existing.datamos_adm_area, inherited.datamos_adm_area),
    coalesce(existing.datamos_district, inherited.datamos_district),
    coalesce(nullif(existing.datamos_kad_n, '[]'::jsonb), inherited.datamos_kad_n, '[]'::jsonb),
    coalesce(nullif(existing.datamos_kad_zu, '[]'::jsonb), inherited.datamos_kad_zu, '[]'::jsonb),
    CURRENT_TIMESTAMP
FROM deduplicated document
LEFT JOIN address_search existing
  ON existing.object_id = document.object_id
 AND existing.hierarchy_type = $2
 AND (existing.region_code = document.region_code OR existing.region_code IS NULL)
LEFT JOIN LATERAL (
    SELECT parent.*
    FROM unnest(document.path_arr) WITH ORDINALITY AS path(object_id, position)
    JOIN address_search parent
      ON parent.object_id = path.object_id
     AND parent.hierarchy_type = $2
     AND (parent.region_code = document.region_code OR parent.region_code IS NULL)
    WHERE parent.object_id <> document.object_id
      AND (
          (parent.geo_lat IS NOT NULL AND parent.geo_lon IS NOT NULL)
          OR parent.datamos_global_id IS NOT NULL
          OR parent.datamos_unom IS NOT NULL
          OR parent.datamos_nreg IS NOT NULL
          OR nullif(btrim(parent.datamos_adm_area), '') IS NOT NULL
          OR nullif(btrim(parent.datamos_district), '') IS NOT NULL
          OR parent.datamos_kad_n <> '[]'::jsonb
          OR parent.datamos_kad_zu <> '[]'::jsonb
      )
    ORDER BY path.position DESC
    LIMIT 1
) inherited ON true
ON CONFLICT (region_code, object_id, hierarchy_type) DO UPDATE SET
    object_guid = EXCLUDED.object_guid,
    address_string = EXCLUDED.address_string,
    level = EXCLUDED.level,
    postal_code = EXCLUDED.postal_code,
    okato = EXCLUDED.okato,
    oktmo = EXCLUDED.oktmo,
    geo_lat = EXCLUDED.geo_lat,
    geo_lon = EXCLUDED.geo_lon,
    geocoded_at = EXCLUDED.geocoded_at,
    geocode_source = EXCLUDED.geocode_source,
    geocode_osm_type = EXCLUDED.geocode_osm_type,
    geocode_osm_id = EXCLUDED.geocode_osm_id,
    geocode_display_name = EXCLUDED.geocode_display_name,
    address_source = EXCLUDED.address_source,
    address_source_id = EXCLUDED.address_source_id,
    address_source_updated_at = EXCLUDED.address_source_updated_at,
    datamos_global_id = EXCLUDED.datamos_global_id,
    datamos_unom = EXCLUDED.datamos_unom,
    datamos_nreg = EXCLUDED.datamos_nreg,
    datamos_adm_area = EXCLUDED.datamos_adm_area,
    datamos_district = EXCLUDED.datamos_district,
    datamos_kad_n = EXCLUDED.datamos_kad_n,
    datamos_kad_zu = EXCLUDED.datamos_kad_zu,
    updated_at = EXCLUDED.updated_at
SQL,
            $selected,
            $targetTable,
        );
    }

    private function __construct() {}
}
