<?php

declare(strict_types=1);

namespace App;

use App\Application\DependencyInjection\HttpRuntimeModule;
use App\Application\MessageBus\MessageBusRegistrySource;
use App\Application\MessageBus\MessageCodecRegistryLoader;
use App\Application\Runtime\HttpRuntime;
use App\Config\EnvironmentSnapshot;
use Thesis\Dic;

final class Bootstrap
{
    public static function run(): void
    {
        Dic::run(
            new HttpRuntimeModule(
                EnvironmentSnapshot::capture(),
                MessageBusRegistrySource::fromFile(dirname(__DIR__) . '/config/message_bus_http.php'),
                MessageCodecRegistryLoader::fromFile(dirname(__DIR__) . '/config/message_bus_codecs.php'),
            ),
            static fn(HttpRuntime $runtime) => $runtime->run(),
        );
    }
}
