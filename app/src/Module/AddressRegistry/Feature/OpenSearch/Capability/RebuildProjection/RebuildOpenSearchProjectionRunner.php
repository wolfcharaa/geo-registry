<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\OpenSearch\Capability\RebuildProjection;

final readonly class RebuildOpenSearchProjectionRunner
{
    public function __construct(
        private RebuildOpenSearchProjectionPort $rebuilder,
    ) {}

    public function run(RebuildOpenSearchProjection $command): RebuildOpenSearchProjectionResult
    {
        return $this->rebuilder->rebuild($command);
    }
}
