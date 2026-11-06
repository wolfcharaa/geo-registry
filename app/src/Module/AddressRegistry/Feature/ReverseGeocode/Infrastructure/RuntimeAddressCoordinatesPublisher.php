<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\ReverseGeocode\Infrastructure;

use Amp\CancelledException;
use App\Module\AddressRegistry\Capability\AddressDocument;
use App\Module\AddressRegistry\Feature\CoordinateEnrichment\Capability\AddressCoordinatesResolved;
use App\Module\AddressRegistry\Feature\ReverseGeocode\Capability\MissingAddressCoordinatesPublisher;
use App\Module\AddressRegistry\Feature\ReverseGeocode\Capability\ReverseGeocodeLocation;
use Fedor\MessageBus\Context\MessageContextInterface;
use Fedor\MessageBus\Publication\PublishFailed;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class RuntimeAddressCoordinatesPublisher implements MissingAddressCoordinatesPublisher
{
    public function __construct(private LoggerInterface $logger) {}

    public function publishIfMissing(
        AddressDocument $document,
        ReverseGeocodeLocation $location,
        MessageContextInterface $context,
    ): void {
        if ($document->latitude !== null && $document->longitude !== null) {
            return;
        }

        try {
            $context->publish(new AddressCoordinatesResolved(
                objectId: $document->objectId,
                lat: $location->latitude,
                lon: $location->longitude,
                displayName: $location->displayName,
                osmType: $location->sourceType,
                osmId: $location->sourceId,
                source: 'nominatim',
                regionCode: $document->regionCode,
            ));
        } catch (CancelledException $exception) {
            throw $exception;
        } catch (PublishFailed $exception) {
            $previous = $exception->getPrevious();
            if ($previous instanceof CancelledException) {
                throw $previous;
            }
            $this->logger->warning('Runtime address coordinate update was not queued', [
                'object_id' => $document->objectId,
                'failure_count' => count($exception->result()->failures()),
                'exception_class' => $exception::class,
            ]);
        } catch (Throwable $exception) {
            $this->logger->warning('Runtime address coordinate publish failed', [
                'object_id' => $document->objectId,
                'exception_class' => $exception::class,
            ]);
        }
    }

}
