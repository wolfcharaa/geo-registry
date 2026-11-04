<?php

declare(strict_types=1);

namespace App\Application\Worker;

use App\Application\DependencyInjection\WorkerRuntimeModule;
use App\Application\MessageBus\MessageBusRegistrySource;
use App\Config\EnvironmentSnapshot;
use Closure;
use Fedor\MessageBus\Codec\MessageCodecRegistry;
use Fedor\MessageBus\Worker\Runtime\WorkerRuntime;
use Fedor\MessageBus\Worker\Runtime\WorkerRuntimeProviderInterface;
use Thesis\Dic;

final readonly class ApplicationWorkerRuntimeProvider implements WorkerRuntimeProviderInterface
{
    public function __construct(
        private EnvironmentSnapshot $environment,
        private MessageBusRegistrySource $messageBusRegistry,
        private ?MessageCodecRegistry $messageCodecs = null,
    ) {}

    /**
     * @template TResult
     * @param Closure(WorkerRuntime): TResult $operation
     * @return TResult
     */
    public function run(Closure $operation): mixed
    {
        return Dic::run(
            new WorkerRuntimeModule(
                $this->environment,
                $this->messageBusRegistry,
                $this->messageCodecs,
            ),
            $operation,
        );
    }
}
