<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\DependencyInjection;

use RuntimeException;
use Testo\Assert;
use Testo\Test;

#[Test]
final class AddressSearchRebuildRuntimeBoundaryTest
{
    public function testBlockingAddressSearchRebuildIsComposedOnlyInTheCliRuntime(): void
    {
        $application = dirname(__DIR__, 4);
        $cli = self::source($application . '/src/Application/DependencyInjection/CliRuntimeModule.php');
        $http = self::source($application . '/src/Application/DependencyInjection/HttpRuntimeModule.php');
        $worker = self::source($application . '/src/Application/DependencyInjection/WorkerRuntimeModule.php');
        $coreRegistry = self::source($application . '/config/message_bus_registry.php');
        $httpRegistry = self::source($application . '/config/message_bus_http.php');

        Assert::true(str_contains($cli, 'AddressSearchRebuildCliModule'));
        Assert::false(str_contains($http, 'AddressSearchRebuild'));
        Assert::false(str_contains($worker, 'AddressSearchRebuild'));
        Assert::false(str_contains($coreRegistry, 'RebuildAddressSearch'));
        Assert::false(str_contains($httpRegistry, 'RebuildAddressSearch'));
    }

    private static function source(string $path): string
    {
        $source = file_get_contents($path);
        if (!is_string($source)) {
            throw new RuntimeException("Unable to read runtime composition source: {$path}");
        }

        return $source;
    }
}
