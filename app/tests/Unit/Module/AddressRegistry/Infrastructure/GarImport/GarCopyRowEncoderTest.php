<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Infrastructure\GarImport\GarCopyRowEncoder;
use Testo\Assert;
use Testo\Test;

#[Test]
final class GarCopyRowEncoderTest
{
    public function testItEncodesPostgresCopyTextWithoutConfusingNullAndEscapedData(): void
    {
        $row = new GarCopyRowEncoder()->encode([
            null,
            '\\N',
            "tab\tline\nreturn\rslash\\",
        ]);

        Assert::same($row, "\\N\t\\\\N\ttab\\tline\\nreturn\\rslash\\\\\n");
    }
}
