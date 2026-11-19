<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Infrastructure\GarImport\GarFusedRangeTaskAdmission;
use Testo\Assert;
use Testo\Test;

#[Test]
final class GarFusedRangeTaskAdmissionTest
{
    public function testItSharesTheParserTaskLimitAcrossFiles(): void
    {
        $admission = new GarFusedRangeTaskAdmission(2);

        Assert::true($admission->tryAcquire('file-a:1'));
        Assert::true($admission->tryAcquire('file-b:1'));
        Assert::same($admission->tasks(), 2);
        Assert::false($admission->tryAcquire('file-a:2'));

        $admission->release('file-a:1');

        Assert::true($admission->tryAcquire('file-a:2'));
        Assert::same($admission->tasks(), 2);
    }
}
