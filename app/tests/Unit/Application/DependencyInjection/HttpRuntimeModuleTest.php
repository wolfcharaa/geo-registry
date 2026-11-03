<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\DependencyInjection;

use App\Application\DependencyInjection\HttpRuntimeModule;
use App\Application\Runtime\HttpRuntime;
use App\Config\EnvironmentSnapshot;
use Testo\Assert;
use Testo\Test;
use Thesis\Dic;

#[Test]
final class HttpRuntimeModuleTest
{
    public function testItBuildsAndDisposesTheHttpRuntimeGraph(): void
    {
        $runtimeClass = Dic::run(
            new HttpRuntimeModule(new EnvironmentSnapshot([])),
            static fn(HttpRuntime $runtime): string => $runtime::class,
        );

        Assert::same($runtimeClass, HttpRuntime::class);
    }
}
