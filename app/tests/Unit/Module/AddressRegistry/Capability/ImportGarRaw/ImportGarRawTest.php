<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry\Capability\ImportGarRaw;

use App\Module\AddressRegistry\Capability\ImportGarRaw\ImportGarRaw;
use InvalidArgumentException;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;

#[Test]
final class ImportGarRawTest
{
    public function testItUsesABoundedRawOnlyTopology(): void
    {
        $command = new ImportGarRaw('/data/gar');

        Assert::same($command->primaryWorkers, 1);
        Assert::same($command->childWorkersPerPrimary, 8);
        Assert::same($command->pipelineFile, 'auto');
        Assert::same($command->fragmentMiB, 8);
        Assert::same($command->deferPrimaryKeys, false);
        Assert::same($command->deferForeignKeys, false);
    }

    public function testItRejectsATopologyAboveTheProcessBudget(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ImportGarRaw('/data/gar', primaryWorkers: 6, childWorkersPerPrimary: 2);
    }

    public function testItRejectsDeferredPrimaryKeysWithActiveForeignKeys(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ImportGarRaw('/data/gar', deferPrimaryKeys: true);
    }
}
