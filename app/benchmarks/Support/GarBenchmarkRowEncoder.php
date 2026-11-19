<?php

declare(strict_types=1);

namespace App\Benchmark\Support;

interface GarBenchmarkRowEncoder
{
    /** @param list<?string> $values */
    public function encode(array $values): string;
}
