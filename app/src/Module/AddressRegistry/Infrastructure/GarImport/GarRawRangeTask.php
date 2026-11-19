<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use Fedor\WorkOrchestrator\Contract\ManagedTask;

/** Serializable range locator; XML and encoded rows never cross IPC. */
final readonly class GarRawRangeTask implements ManagedTask
{
    public function __construct(
        public int $operationId,
        public GarXmlSourceFile $file,
        public GarXmlByteRange $range,
        public int $maxEncodedBytes,
    ) {
        if (
            $this->operationId < 1
            || $this->maxEncodedBytes < 8 * 1024 * 1024
            || $this->maxEncodedBytes > 16 * 1024 * 1024
        ) {
            throw new GarImportFailed('GAR raw range task received invalid execution limits.');
        }
    }

    public function handler(): string
    {
        return GarRawRangeTaskHandler::class;
    }
}
