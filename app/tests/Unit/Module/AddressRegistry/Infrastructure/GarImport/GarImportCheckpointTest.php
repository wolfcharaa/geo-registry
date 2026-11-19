<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Infrastructure\GarImport\GarImportCheckpoint;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarImportCommittedRange;
use Testo\Assert;
use Testo\Test;

#[Test]
final class GarImportCheckpointTest
{
    public function testItFoldsOnlyTheContiguousCommittedPrefix(): void
    {
        $checkpoint = GarImportCheckpoint::fold(10, [
            new GarImportCommittedRange(11, 20),
            new GarImportCommittedRange(21, 30),
            new GarImportCommittedRange(41, 50),
        ]);

        Assert::same($checkpoint, 30);
    }

    public function testItDoesNotCrossTheFirstGap(): void
    {
        $checkpoint = GarImportCheckpoint::fold(10, [
            new GarImportCommittedRange(21, 30),
            new GarImportCommittedRange(31, 40),
        ]);

        Assert::same($checkpoint, 10);
    }
}
