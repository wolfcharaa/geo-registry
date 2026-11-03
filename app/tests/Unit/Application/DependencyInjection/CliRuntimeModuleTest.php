<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\DependencyInjection;

use App\Application\DependencyInjection\CliRuntimeModule;
use App\Application\Runtime\CliRuntime;
use App\Config\EnvironmentSnapshot;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Test;
use Thesis\Dic;

#[Test]
final class CliRuntimeModuleTest
{
    public function testItRunsTheConsoleFromAnIndependentGraph(): void
    {
        $output = new BufferedOutput();
        $exitCode = Dic::run(
            new CliRuntimeModule(new EnvironmentSnapshot([
                'APP_NAME' => 'geo-registry-test',
                'APP_VERSION' => 'test',
            ])),
            static fn(CliRuntime $runtime): int => $runtime->run(
                new ArrayInput(['command' => 'list', '--no-ansi' => true]),
                $output,
            ),
        );

        Assert::same($exitCode, 0);
        Assert::true(str_contains($output->fetch(), 'geo-registry-test'));
    }
}
