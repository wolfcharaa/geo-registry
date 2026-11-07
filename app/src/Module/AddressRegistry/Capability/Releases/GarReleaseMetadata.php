<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\Releases;

final readonly class GarReleaseMetadata
{
    public function __construct(
        public int $versionId,
        public ?string $textVersion,
        public ?string $date,
        public ?string $fullXmlUrl,
        public ?string $deltaXmlUrl,
    ) {}

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'version_id' => $this->versionId,
            'text_version' => $this->textVersion,
            'date' => $this->date,
            'full_xml_url' => $this->fullXmlUrl,
            'delta_xml_url' => $this->deltaXmlUrl,
        ];
    }
}
