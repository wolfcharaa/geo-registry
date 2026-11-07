<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry\Capability\ImportGar;

use App\Module\AddressRegistry\Capability\ImportGar\ImportGar;
use InvalidArgumentException;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;

#[Test]
final class ImportGarTest
{
    public function testItUsesBoundedParallelCopyDefaults(): void
    {
        $command = new ImportGar('/data/gar');

        Assert::same($command->batchSize, 50_000);
        Assert::same($command->workers, 2);
    }

    public function testItRejectsAnUnsafeWorkerCount(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ImportGar('/data/gar', workers: 17);
    }

    public function testItAcceptsAnExplicitRegionSelection(): void
    {
        $command = new ImportGar('/data/gar', regions: [50, 77]);

        Assert::same($command->regions, [50, 77]);
    }

    public function testItRejectsDuplicateRegions(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ImportGar('/data/gar', regions: [77, 77]);
    }
}
