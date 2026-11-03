<?php

declare(strict_types=1);

namespace App\Application\Console\Command;

use App\Application\MessageBus\ApplicationRegistryCompiler;
use App\Application\MessageBus\MessageBusRegistrySource;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class MessageBusRegistryCheckCommand extends Command
{
    public function __construct(
        private readonly MessageBusRegistrySource $source,
        private readonly ApplicationRegistryCompiler $compiler,
    ) {
        parent::__construct('message-bus:registry:check');
    }

    protected function configure(): void
    {
        $this->setDescription('Compile and validate the explicit application MessageBus registry.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = $this->compiler->compile($this->source);
        $output->writeln(sprintf(
            'bindings=%d source_hash=%s',
            count($registry->compiled()->bindings()),
            $registry->definition()->sourceHash,
        ));

        return self::SUCCESS;
    }
}
