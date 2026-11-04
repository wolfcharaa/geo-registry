<?php

declare(strict_types=1);

use App\Application\MessageBus\MessageBusRegistrySource;
use App\Application\MessageBus\MessageCodecRegistryLoader;
use App\Application\Worker\ApplicationWorkerRuntimeProvider;
use App\Config\EnvironmentSnapshot;

return new ApplicationWorkerRuntimeProvider(
    EnvironmentSnapshot::capture(),
    MessageBusRegistrySource::fromFile(__DIR__ . '/message_bus_registry.php'),
    MessageCodecRegistryLoader::fromFile(__DIR__ . '/message_bus_codecs.php'),
);
