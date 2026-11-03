<?php

declare(strict_types=1);

namespace App;

use App\Application\DependencyInjection\HttpRuntimeModule;
use App\Application\Runtime\HttpRuntime;
use App\Config\EnvironmentSnapshot;
use Thesis\Dic;

final class Bootstrap
{
    public static function run(): void
    {
        Dic::run(
            new HttpRuntimeModule(EnvironmentSnapshot::capture()),
            static fn(HttpRuntime $runtime) => $runtime->run(),
        );
    }
}
