<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\Postgres;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Postgres\PostgresConnectionPool;
use App\Module\AddressRegistry\Capability\FindAddressById\FindAddressByIdPort;
use App\Module\AddressRegistry\Capability\FindAddressById\FindAddressByIdUnavailable;
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

    public function __construct(
        private PostgresConnectionPool $postgres,
        private PostgresAddressDocumentHydrator $hydrator,
    ) {}

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
                $documents[] = $this->hydrator->hydrate($row);
            }

            $cancellation->throwIfRequested();

            return $documents;
        } catch (CancelledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new FindAddressByIdUnavailable($exception);
        }
    }

}
