<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;

/** @implements Task<GarParsedXmlRange, never, never> */
final readonly class GarParseXmlRangeTask implements Task
{
    public function __construct(
        private GarXmlSourceFile $file,
        private GarXmlByteRange $range,
    ) {}

    public function run(Channel $channel, Cancellation $cancellation): GarParsedXmlRange
    {
        return new GarXmlRangeParser()->parse($this->file, $this->range, $cancellation);
    }
}
