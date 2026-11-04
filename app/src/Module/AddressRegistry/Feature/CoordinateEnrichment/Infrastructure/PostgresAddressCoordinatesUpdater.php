<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\CoordinateEnrichment\Infrastructure;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Postgres\PostgresConnectionPool;
use App\Module\AddressRegistry\Feature\CoordinateEnrichment\Capability\AddressCoordinatesResolved;
use App\Module\AddressRegistry\Feature\CoordinateEnrichment\Capability\AddressCoordinatesUpdater;
use App\Module\AddressRegistry\Feature\CoordinateEnrichment\Capability\AddressCoordinatesUpdateResult;
use RuntimeException;
use Throwable;

final readonly class PostgresAddressCoordinatesUpdater implements AddressCoordinatesUpdater
{
    private const UPDATE = <<<'SQL'
WITH updated AS (
    UPDATE address_search
    SET
        geo_lat = :geo_lat,
        geo_lon = :geo_lon,
        geocoded_at = CURRENT_TIMESTAMP,
        geocode_source = :geocode_source,
        geocode_osm_type = :geocode_osm_type,
        geocode_osm_id = :geocode_osm_id,
        geocode_display_name = :geocode_display_name,
        updated_at = CURRENT_TIMESTAMP
    WHERE object_id = :object_id
      AND (CAST(:region_code AS smallint) IS NULL OR region_code = CAST(:region_code AS smallint))
      AND (geo_lat IS NULL OR geo_lon IS NULL)
    RETURNING 1
)
SELECT
    (SELECT count(*)::integer FROM updated) AS updated_rows,
    EXISTS (
        SELECT 1
        FROM address_search
        WHERE object_id = :object_id
          AND (CAST(:region_code AS smallint) IS NULL OR region_code = CAST(:region_code AS smallint))
    ) AS object_exists
SQL;

    public function __construct(private PostgresConnectionPool $postgres) {}

    public function update(
        AddressCoordinatesResolved $event,
        Cancellation $cancellation,
    ): AddressCoordinatesUpdateResult {
        $cancellation->throwIfRequested();

        try {
            $row = $this->postgres->execute(self::UPDATE, [
                'object_id' => $event->objectId,
                'region_code' => $event->regionCode,
                'geo_lat' => $event->lat,
                'geo_lon' => $event->lon,
                'geocode_source' => $event->source,
                'geocode_osm_type' => $event->osmType,
                'geocode_osm_id' => $event->osmId,
                'geocode_display_name' => $event->displayName,
            ])->fetchRow();
            $cancellation->throwIfRequested();
        } catch (CancelledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new RuntimeException('PostgreSQL address coordinate update failed.', previous: $exception);
        }

        $updatedRows = $row['updated_rows'] ?? null;
        $objectExists = $row['object_exists'] ?? null;
        if ((!is_int($updatedRows) && !is_string($updatedRows)) || !is_bool($objectExists)) {
            throw new RuntimeException('PostgreSQL address coordinate update returned an invalid result.');
        }

        return new AddressCoordinatesUpdateResult((int) $updatedRows, $objectExists);
    }
}
