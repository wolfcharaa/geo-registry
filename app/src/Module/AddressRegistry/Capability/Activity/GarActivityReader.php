<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\Activity;

interface GarActivityReader
{
    public function read(int $limit): GarActivitySnapshot;
}
