<?php

declare(strict_types=1);

namespace App\Application\Runtime;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class CliRuntime
{
    public function __construct(private Application $application)
    {
        $this->application->setAutoExit(false);
    }

    public function run(?InputInterface $input = null, ?OutputInterface $output = null): int
    {
        return $this->application->run($input, $output);
    }
}
