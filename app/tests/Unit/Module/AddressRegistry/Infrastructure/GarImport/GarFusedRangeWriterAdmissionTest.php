<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Infrastructure\GarImport\GarFusedRangeWriterAdmission;
use Testo\Assert;
use Testo\Test;

#[Test]
final class GarFusedRangeWriterAdmissionTest
{
    public function testItSharesWriterAndByteLimitsAcrossRangeTokens(): void
    {
        $admission = new GarFusedRangeWriterAdmission(2, 10);

        Assert::true($admission->tryAcquire('file-a:1', 7));
        Assert::true($admission->tryAcquire('file-b:1', 3));
        Assert::same($admission->writers(), 2);
        Assert::same($admission->bytes(), 10);
        Assert::false($admission->tryAcquire('file-c:1', 1));

        $admission->release('file-a:1');

        Assert::true($admission->tryAcquire('file-c:1', 4));
        Assert::same($admission->writers(), 2);
        Assert::same($admission->bytes(), 7);
    }

    public function testItAllowsOneOversizedRangeToAvoidAByteLimitDeadlock(): void
    {
        $admission = new GarFusedRangeWriterAdmission(2, 10);

        Assert::true($admission->tryAcquire('file-a:1', 11));
        Assert::false($admission->tryAcquire('file-b:1', 1));
    }
}
