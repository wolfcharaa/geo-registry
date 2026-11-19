<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/** One-service child-process container for raw range work. */
final readonly class GarRawRangeTaskContainer implements ContainerInterface
{
    public function __construct(private GarRawRangeTaskHandler $handler) {}

    public function get(string $id): mixed
    {
        if ($id !== GarRawRangeTaskHandler::class) {
            throw new class (\sprintf('Unknown GAR raw task service %s.', $id)) extends RuntimeException implements NotFoundExceptionInterface {};
        }

        return $this->handler;
    }

    public function has(string $id): bool
    {
        return $id === GarRawRangeTaskHandler::class;
    }
}
