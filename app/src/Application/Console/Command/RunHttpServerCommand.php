<?php

declare(strict_types=1);

namespace App\Application\Console\Command;

use App\Application\Runtime\HttpRuntime;
use Symfony\Component\Console\Command\Command;

final readonly class RunHttpServerCommand
{
    public function __construct(
        private HttpRuntime $runtime,
    ) {}

    public function __invoke(): int
    {
        $this->runtime->run();

        return Command::SUCCESS;
    }
}
