<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\UserInterface\Cli;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use App\Module\AddressRegistry\Capability\ImportGar\RunGarIndexBuildWorker;
use App\Module\AddressRegistry\Capability\ImportGar\RunGarIndexBuildWorkerPort;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RunGarIndexBuildWorkerCommand extends Command
{
    public function __construct(private readonly RunGarIndexBuildWorkerPort $worker)
    {
        parent::__construct('gar:import:index-worker');
        $this->setHidden(true);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Restore one secondary GAR index for an initial-load operation.')
            ->addArgument('operation', InputArgument::REQUIRED, 'GAR import operation id.')
            ->addArgument('index', InputArgument::REQUIRED, 'Snapshot index name.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $operation = $input->getArgument('operation');
            $index = $input->getArgument('index');
            if (
                !\is_string($operation)
                || !\is_string($index)
                || \preg_match('/^[0-9]+$/D', $operation) !== 1
            ) {
                throw new InvalidArgumentException('GAR index worker arguments must have valid scalar values.');
            }
            $this->worker->run(new RunGarIndexBuildWorker((int) $operation, $index));
        } catch (InvalidArgumentException|GarImportFailed $error) {
            $output->writeln('<error>' . $error->getMessage() . '</error>');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
