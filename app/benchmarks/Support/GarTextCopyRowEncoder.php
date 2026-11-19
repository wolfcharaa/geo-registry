<?php

declare(strict_types=1);

namespace App\Benchmark\Support;

use App\Module\AddressRegistry\Infrastructure\GarImport\GarCopyRowEncoder;

final readonly class GarTextCopyRowEncoder implements GarBenchmarkRowEncoder
{
    private GarCopyRowEncoder $encoder;

    public function __construct()
    {
        $this->encoder = new GarCopyRowEncoder();
    }

    public function encode(array $values): string
    {
        return $this->encoder->encode($values);
    }
}
