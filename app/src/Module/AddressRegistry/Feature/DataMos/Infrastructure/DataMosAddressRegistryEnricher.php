<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\DataMos\Infrastructure;

use function max;
use function microtime;

use PDO;

final class DataMosAddressRegistryEnricher
{
    public function __construct(
        private readonly PDO $db,
        private readonly int $regionCode = 77,
    ) {}

    public function enrich(bool $insertMissing = true): DataMosAddressRegistryEnrichResult
    {
        $startedAt = microtime(true);
        $updated = $this->updateExistingSearchRows();
        $updated += $this->inheritExistingSearchRows();
        $inserted = $insertMissing ? $this->insertMissingSearchRows() : 0;

        return new DataMosAddressRegistryEnrichResult(
            updatedSearchRows: $updated,
            insertedSearchRows: $inserted,
            duration: microtime(true) - $startedAt,
        );
    }

    private function updateExistingSearchRows(): int
    {
        return $this->execute(<<<'SQL'
WITH preferred AS MATERIALIZED (
    SELECT DISTINCT ON (n_fias)
        source_key,
        global_id,
        unom,
        nreg,
        n_fias,
        address,
        adm_area,
        district,
        kad_n,
        kad_zu,
        geo_lat,
        geo_lon,
        updated_at
    FROM datamos_address_registry
    WHERE n_fias IS NOT NULL
    ORDER BY n_fias,
             (geo_lat IS NULL OR geo_lon IS NULL),
             (address = ''),
             updated_at DESC,
             id DESC
)
UPDATE address_search s
SET address_source = CASE
        WHEN s.address_source = 'datamos' THEN 'datamos'
        WHEN s.address_source LIKE '%datamos%' THEN s.address_source
        ELSE 'gar+datamos'
    END,
    address_source_id = d.source_key,
    address_source_updated_at = CURRENT_TIMESTAMP,
    datamos_global_id = d.global_id,
    datamos_unom = d.unom,
    datamos_nreg = d.nreg,
    datamos_adm_area = d.adm_area,
    datamos_district = d.district,
    datamos_kad_n = d.kad_n,
    datamos_kad_zu = d.kad_zu,
    geo_lat = CASE
        WHEN s.geo_lat IS NULL AND s.geo_lon IS NULL AND d.geo_lat IS NOT NULL AND d.geo_lon IS NOT NULL THEN d.geo_lat
        ELSE s.geo_lat
    END,
    geo_lon = CASE
        WHEN s.geo_lat IS NULL AND s.geo_lon IS NULL AND d.geo_lat IS NOT NULL AND d.geo_lon IS NOT NULL THEN d.geo_lon
        ELSE s.geo_lon
    END,
    geocoded_at = CASE
        WHEN s.geo_lat IS NULL AND s.geo_lon IS NULL AND d.geo_lat IS NOT NULL AND d.geo_lon IS NOT NULL THEN CURRENT_TIMESTAMP
        ELSE s.geocoded_at
    END,
    geocode_source = CASE
        WHEN s.geo_lat IS NULL AND s.geo_lon IS NULL AND d.geo_lat IS NOT NULL AND d.geo_lon IS NOT NULL THEN 'datamos'
        ELSE s.geocode_source
    END,
    geocode_display_name = CASE
        WHEN s.geo_lat IS NULL AND s.geo_lon IS NULL AND d.geo_lat IS NOT NULL AND d.geo_lon IS NOT NULL THEN d.address
        ELSE s.geocode_display_name
    END,
    updated_at = CURRENT_TIMESTAMP
FROM preferred d
WHERE s.region_code = :region_code
  AND s.object_guid = d.n_fias
SQL);
    }

    private function inheritExistingSearchRows(): int
    {
        return $this->execute(<<<'SQL'
WITH targets AS MATERIALIZED (
    SELECT s.object_id, s.hierarchy_type, s.level, h.path_arr
    FROM address_search s
    JOIN administrative_reestr_hierarchy h
      ON s.hierarchy_type = 'adm'
     AND h.object_id = s.object_id
     AND h.is_active = true
     AND h.end_date > CURRENT_DATE
    WHERE s.region_code = :region_code
      AND s.level >= 11
      AND (
          s.geo_lat IS NULL
          OR s.geo_lon IS NULL
          OR s.datamos_global_id IS NULL
          OR s.datamos_unom IS NULL
          OR s.datamos_nreg IS NULL
          OR s.datamos_adm_area IS NULL
          OR s.datamos_district IS NULL
          OR s.datamos_kad_n IS NULL
          OR s.datamos_kad_n = '[]'::jsonb
          OR s.datamos_kad_zu IS NULL
          OR s.datamos_kad_zu = '[]'::jsonb
      )

    UNION ALL

    SELECT s.object_id, s.hierarchy_type, s.level, h.path_arr
    FROM address_search s
    JOIN municipal_reestr_hierarchy h
      ON s.hierarchy_type = 'mun'
     AND h.object_id = s.object_id
     AND h.is_active = true
     AND h.end_date > CURRENT_DATE
    WHERE s.region_code = :region_code
      AND s.level >= 11
      AND (
          s.geo_lat IS NULL
          OR s.geo_lon IS NULL
          OR s.datamos_global_id IS NULL
          OR s.datamos_unom IS NULL
          OR s.datamos_nreg IS NULL
          OR s.datamos_adm_area IS NULL
          OR s.datamos_district IS NULL
          OR s.datamos_kad_n IS NULL
          OR s.datamos_kad_n = '[]'::jsonb
          OR s.datamos_kad_zu IS NULL
          OR s.datamos_kad_zu = '[]'::jsonb
      )
),
inherited AS MATERIALIZED (
    SELECT
        targets.object_id,
        targets.hierarchy_type,
        parent.geo_lat,
        parent.geo_lon,
        parent.geocoded_at,
        parent.geocode_source,
        parent.geocode_osm_type,
        parent.geocode_osm_id,
        parent.geocode_display_name,
        parent.address_source,
        parent.address_source_id,
        parent.address_source_updated_at,
        parent.datamos_global_id,
        parent.datamos_unom,
        parent.datamos_nreg,
        parent.datamos_adm_area,
        parent.datamos_district,
        parent.datamos_kad_n,
        parent.datamos_kad_zu
    FROM targets
    JOIN LATERAL (
        SELECT parent.*
        FROM unnest(targets.path_arr) WITH ORDINALITY AS path(object_id, ord)
        JOIN address_search parent
          ON parent.region_code = :region_code
         AND parent.object_id = path.object_id
         AND parent.hierarchy_type = targets.hierarchy_type
        WHERE parent.object_id <> targets.object_id
          AND parent.level < targets.level
          AND (
              coalesce(parent.address_source, '') LIKE '%datamos%'
              OR parent.datamos_global_id IS NOT NULL
              OR parent.datamos_unom IS NOT NULL
              OR parent.datamos_nreg IS NOT NULL
              OR nullif(btrim(parent.datamos_adm_area), '') IS NOT NULL
              OR nullif(btrim(parent.datamos_district), '') IS NOT NULL
              OR parent.datamos_kad_n <> '[]'::jsonb
              OR parent.datamos_kad_zu <> '[]'::jsonb
          )
        ORDER BY path.ord DESC
        LIMIT 1
    ) parent ON true
)
UPDATE address_search s
SET
    geo_lat = CASE
        WHEN (s.geo_lat IS NULL OR s.geo_lon IS NULL) AND inherited.geo_lat IS NOT NULL AND inherited.geo_lon IS NOT NULL
            THEN inherited.geo_lat
        ELSE s.geo_lat
    END,
    geo_lon = CASE
        WHEN (s.geo_lat IS NULL OR s.geo_lon IS NULL) AND inherited.geo_lat IS NOT NULL AND inherited.geo_lon IS NOT NULL
            THEN inherited.geo_lon
        ELSE s.geo_lon
    END,
    geocoded_at = CASE
        WHEN (s.geo_lat IS NULL OR s.geo_lon IS NULL) AND inherited.geo_lat IS NOT NULL AND inherited.geo_lon IS NOT NULL
            THEN inherited.geocoded_at
        ELSE s.geocoded_at
    END,
    geocode_source = CASE
        WHEN (s.geo_lat IS NULL OR s.geo_lon IS NULL) AND inherited.geo_lat IS NOT NULL AND inherited.geo_lon IS NOT NULL
            THEN inherited.geocode_source
        ELSE s.geocode_source
    END,
    geocode_osm_type = CASE
        WHEN (s.geo_lat IS NULL OR s.geo_lon IS NULL) AND inherited.geo_lat IS NOT NULL AND inherited.geo_lon IS NOT NULL
            THEN inherited.geocode_osm_type
        ELSE s.geocode_osm_type
    END,
    geocode_osm_id = CASE
        WHEN (s.geo_lat IS NULL OR s.geo_lon IS NULL) AND inherited.geo_lat IS NOT NULL AND inherited.geo_lon IS NOT NULL
            THEN inherited.geocode_osm_id
        ELSE s.geocode_osm_id
    END,
    geocode_display_name = CASE
        WHEN (s.geo_lat IS NULL OR s.geo_lon IS NULL) AND inherited.geo_lat IS NOT NULL AND inherited.geo_lon IS NOT NULL
            THEN inherited.geocode_display_name
        ELSE s.geocode_display_name
    END,
    address_source = CASE
        WHEN coalesce(s.address_source, '') = 'datamos' THEN 'datamos'
        WHEN coalesce(s.address_source, '') LIKE '%datamos%' THEN s.address_source
        WHEN coalesce(inherited.address_source, '') LIKE '%datamos%' THEN 'gar+datamos'
        ELSE coalesce(s.address_source, 'gar')
    END,
    address_source_id = coalesce(s.address_source_id, inherited.address_source_id),
    address_source_updated_at = CASE
        WHEN s.address_source_updated_at IS NOT NULL THEN s.address_source_updated_at
        WHEN inherited.address_source_id IS NOT NULL THEN coalesce(inherited.address_source_updated_at, CURRENT_TIMESTAMP)
        ELSE s.address_source_updated_at
    END,
    datamos_global_id = coalesce(s.datamos_global_id, inherited.datamos_global_id),
    datamos_unom = coalesce(s.datamos_unom, inherited.datamos_unom),
    datamos_nreg = coalesce(s.datamos_nreg, inherited.datamos_nreg),
    datamos_adm_area = coalesce(s.datamos_adm_area, inherited.datamos_adm_area),
    datamos_district = coalesce(s.datamos_district, inherited.datamos_district),
    datamos_kad_n = CASE
        WHEN s.datamos_kad_n IS NOT NULL AND s.datamos_kad_n <> '[]'::jsonb THEN s.datamos_kad_n
        ELSE coalesce(inherited.datamos_kad_n, '[]'::jsonb)
    END,
    datamos_kad_zu = CASE
        WHEN s.datamos_kad_zu IS NOT NULL AND s.datamos_kad_zu <> '[]'::jsonb THEN s.datamos_kad_zu
        ELSE coalesce(inherited.datamos_kad_zu, '[]'::jsonb)
    END,
    updated_at = CURRENT_TIMESTAMP
FROM inherited
WHERE s.object_id = inherited.object_id
  AND s.region_code = :region_code
  AND s.hierarchy_type = inherited.hierarchy_type
  AND (
      ((s.geo_lat IS NULL OR s.geo_lon IS NULL) AND inherited.geo_lat IS NOT NULL AND inherited.geo_lon IS NOT NULL)
      OR (s.datamos_global_id IS NULL AND inherited.datamos_global_id IS NOT NULL)
      OR (s.datamos_unom IS NULL AND inherited.datamos_unom IS NOT NULL)
      OR (s.datamos_nreg IS NULL AND inherited.datamos_nreg IS NOT NULL)
      OR (s.datamos_adm_area IS NULL AND inherited.datamos_adm_area IS NOT NULL)
      OR (s.datamos_district IS NULL AND inherited.datamos_district IS NOT NULL)
      OR ((s.datamos_kad_n IS NULL OR s.datamos_kad_n = '[]'::jsonb) AND inherited.datamos_kad_n <> '[]'::jsonb)
      OR ((s.datamos_kad_zu IS NULL OR s.datamos_kad_zu = '[]'::jsonb) AND inherited.datamos_kad_zu <> '[]'::jsonb)
  )
SQL);
    }

    private function insertMissingSearchRows(): int
    {
        return $this->execute(<<<'SQL'
WITH preferred AS MATERIALIZED (
    SELECT DISTINCT ON (coalesce(n_fias::text, source_key))
        id,
        source_key,
        global_id,
        unom,
        nreg,
        n_fias,
        address,
        simple_address,
        obj_type,
        adm_area,
        district,
        kad_n,
        kad_zu,
        geo_lat,
        geo_lon,
        updated_at
    FROM datamos_address_registry
    WHERE btrim(coalesce(nullif(address, ''), simple_address, '')) <> ''
    ORDER BY coalesce(n_fias::text, source_key),
             (geo_lat IS NULL OR geo_lon IS NULL),
             updated_at DESC,
             id DESC
),
missing AS MATERIALIZED (
    SELECT
        CASE
            WHEN nreg IS NOT NULL THEN (-1000000000000::bigint - nreg)
            WHEN unom IS NOT NULL THEN (-2000000000000::bigint - unom)
            WHEN global_id IS NOT NULL THEN (-3000000000000::bigint - global_id)
            ELSE (-4000000000000::bigint - id)
        END AS synthetic_object_id,
        CASE
            WHEN lower(coalesce(obj_type, '')) LIKE '%помещ%' THEN 11
            WHEN lower(coalesce(obj_type, '')) LIKE '%комнат%' THEN 12
            WHEN lower(coalesce(obj_type, '')) LIKE '%машино%' THEN 17
            WHEN lower(coalesce(obj_type, '')) LIKE '%зем%' THEN 9
            ELSE 10
        END AS address_level,
        *
    FROM preferred d
    WHERE NOT EXISTS (
        SELECT 1
        FROM address_search s
        WHERE d.n_fias IS NOT NULL
          AND s.object_guid = d.n_fias
    )
),
deduplicated AS MATERIALIZED (
    SELECT DISTINCT ON (synthetic_object_id)
        *
    FROM missing
    ORDER BY synthetic_object_id,
             (geo_lat IS NULL OR geo_lon IS NULL),
             updated_at DESC,
             id DESC
)
INSERT INTO address_search (
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
    geocode_display_name,
    updated_at,
    address_source,
    address_source_id,
    address_source_updated_at,
    datamos_global_id,
    datamos_unom,
    datamos_nreg,
    datamos_adm_area,
    datamos_district,
    datamos_kad_n,
    datamos_kad_zu
)
SELECT
    :region_code,
    synthetic_object_id,
    n_fias,
    btrim(coalesce(nullif(address, ''), simple_address)),
    'adm',
    address_level,
    NULL::varchar(20),
    NULL::varchar(20),
    NULL::varchar(20),
    geo_lat,
    geo_lon,
    CASE WHEN geo_lat IS NOT NULL AND geo_lon IS NOT NULL THEN CURRENT_TIMESTAMP ELSE NULL END,
    CASE WHEN geo_lat IS NOT NULL AND geo_lon IS NOT NULL THEN 'datamos' ELSE NULL END,
    CASE WHEN geo_lat IS NOT NULL AND geo_lon IS NOT NULL THEN address ELSE NULL END,
    CURRENT_TIMESTAMP,
    'datamos',
    source_key,
    CURRENT_TIMESTAMP,
    global_id,
    unom,
    nreg,
    adm_area,
    district,
    kad_n,
    kad_zu
FROM deduplicated
ON CONFLICT (region_code, object_id, hierarchy_type) DO UPDATE SET
    object_guid = EXCLUDED.object_guid,
    address_string = EXCLUDED.address_string,
    level = EXCLUDED.level,
    geo_lat = EXCLUDED.geo_lat,
    geo_lon = EXCLUDED.geo_lon,
    geocoded_at = EXCLUDED.geocoded_at,
    geocode_source = EXCLUDED.geocode_source,
    geocode_display_name = EXCLUDED.geocode_display_name,
    updated_at = EXCLUDED.updated_at,
    address_source = EXCLUDED.address_source,
    address_source_id = EXCLUDED.address_source_id,
    address_source_updated_at = EXCLUDED.address_source_updated_at,
    datamos_global_id = EXCLUDED.datamos_global_id,
    datamos_unom = EXCLUDED.datamos_unom,
    datamos_nreg = EXCLUDED.datamos_nreg,
    datamos_adm_area = EXCLUDED.datamos_adm_area,
    datamos_district = EXCLUDED.datamos_district,
    datamos_kad_n = EXCLUDED.datamos_kad_n,
    datamos_kad_zu = EXCLUDED.datamos_kad_zu
SQL);
    }

    private function execute(string $sql): int
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false) {
            throw new \RuntimeException('Unable to prepare Data.Mos enrichment query.');
        }

        $statement->bindValue(':region_code', $this->regionCode, \PDO::PARAM_INT);
        $statement->execute();

        return max(0, $statement->rowCount());
    }
}
