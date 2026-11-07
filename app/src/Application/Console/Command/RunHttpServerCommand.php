<?php

declare(strict_types=1);

namespace App\Application\Console\Command;

use Amp\Cluster\Cluster;
use App\Application\Runtime\HttpClusterRuntime;
use App\Application\Runtime\HttpRuntime;
use App\Config\HttpServerConfig;
use Symfony\Component\Console\Command\Command;

final readonly class RunHttpServerCommand
{
    /**
     * @param \Closure(): HttpRuntime $runtimeProvider
     * @param \Closure(): HttpClusterRuntime $clusterRuntimeProvider
     */
    public function __construct(
        private HttpServerConfig $config,
        private \Closure $runtimeProvider,
        private \Closure $clusterRuntimeProvider,
    ) {}

    public function __invoke(): int
    {
        if ($this->config->workers > 1 && !Cluster::isWorker()) {
            ($this->clusterRuntimeProvider)()->run();
        } else {
            ($this->runtimeProvider)()->run();
        }

        return Command::SUCCESS;
    }
}
