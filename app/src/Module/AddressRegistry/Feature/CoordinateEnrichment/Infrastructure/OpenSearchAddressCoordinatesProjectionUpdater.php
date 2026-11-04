<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\CoordinateEnrichment\Infrastructure;

use Amp\Cancellation;
use Amp\CancelledException;
use App\Infrastructure\OpenSearch\OpenSearchClient;
use App\Infrastructure\OpenSearch\OpenSearchException;
use App\Module\AddressRegistry\Feature\CoordinateEnrichment\Capability\AddressCoordinatesProjectionUpdater;
use App\Module\AddressRegistry\Feature\CoordinateEnrichment\Capability\AddressCoordinatesResolved;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class OpenSearchAddressCoordinatesProjectionUpdater implements AddressCoordinatesProjectionUpdater
{
    public function __construct(
        private OpenSearchClient $client,
        private string $index,
        private LoggerInterface $logger,
    ) {}

    public function update(AddressCoordinatesResolved $event, Cancellation $cancellation): int
    {
        try {
            return $this->client->updateByQuery(
                $this->index,
                self::query($event),
                [
                    'lang' => 'painless',
                    'source' => 'ctx._source.geo_lat = params.lat; ctx._source.geo_lon = params.lon',
                    'params' => ['lat' => $event->lat, 'lon' => $event->lon],
                ],
                refresh: true,
                cancellation: $cancellation,
            );
        } catch (CancelledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->logger->warning('OpenSearch coordinate projection update failed', [
                'object_id' => $event->objectId,
                'region_code' => $event->regionCode,
                'exception_class' => $exception::class,
                'status' => $exception instanceof OpenSearchException ? $exception->status : null,
            ]);

            return 0;
        }
    }

    /** @return array<string, mixed> */
    private static function query(AddressCoordinatesResolved $event): array
    {
        $filter = [['term' => ['object_id' => $event->objectId]]];
        if ($event->regionCode !== null) {
            $filter[] = ['term' => ['region_code' => $event->regionCode]];
        }

        return ['bool' => ['filter' => $filter]];
    }
}
