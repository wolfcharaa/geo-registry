<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\CoordinateEnrichment\Capability;

use Fedor\MessageBus\Attribute\EventSubscriber;
use Fedor\MessageBus\Context\MessageContextInterface;
use Psr\Log\LoggerInterface;

#[EventSubscriber(
    message: AddressCoordinatesResolved::class,
    flow: 'runtime_async',
    bindingId: 'gar.address_coordinates.update',
    ownerKind: 'feature',
    ownerId: 'AddressRegistry.CoordinateEnrichment',
)]
final readonly class UpdateAddressCoordinatesHandler
{
    public function __construct(
        private AddressCoordinatesUpdater $coordinates,
        private AddressCoordinatesProjectionUpdater $projection,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(AddressCoordinatesResolved $event, MessageContextInterface $context): void
    {
        $result = $this->coordinates->update($event, $context->cancellation());
        $projectedRows = $result->objectExists
            ? $this->projection->update($event, $context->cancellation())
            : 0;

        $this->logger->info('Runtime address coordinates processed', [
            'object_id' => $event->objectId,
            'updated_rows' => $result->updatedRows,
            'projected_rows' => $projectedRows,
            'object_exists' => $result->objectExists,
            'osm_type' => $event->osmType,
            'osm_id' => $event->osmId,
        ]);
    }
}
