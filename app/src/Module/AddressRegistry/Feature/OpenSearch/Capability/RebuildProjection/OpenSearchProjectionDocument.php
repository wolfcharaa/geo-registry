<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\OpenSearch\Capability\RebuildProjection;

use App\Module\AddressRegistry\Capability\AddressAlias;
use App\Module\AddressRegistry\Capability\AddressDocument;
use InvalidArgumentException;

final readonly class OpenSearchProjectionDocument
{
    /** @param list<AddressAlias> $aliases */
    public function __construct(
        public int $regionCode,
        public AddressDocument $document,
        public array $aliases,
    ) {
        if ($this->regionCode < 1 || $this->regionCode > 99) {
            throw new InvalidArgumentException('OpenSearch projection region code must be between 1 and 99.');
        }
        if ($this->document->aliasCount !== count($this->aliases)) {
            throw new InvalidArgumentException('OpenSearch projection alias count must match the alias collection.');
        }
    }

    public function id(): string
    {
        return sprintf(
            '%02d:%s:%d',
            $this->regionCode,
            $this->document->hierarchy->value,
            $this->document->objectId,
        );
    }
}
