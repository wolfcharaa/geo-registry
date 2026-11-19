<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\UserInterface\Cli;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use App\Module\AddressRegistry\Capability\ImportGar\RunGarIndexScheduler;
use App\Module\AddressRegistry\Capability\ImportGar\RunGarIndexSchedulerPort;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RunGarIndexSchedulerCommand extends Command
{
    public function __construct(private readonly RunGarIndexSchedulerPort $scheduler)
    {
        parent::__construct('gar:import:index-scheduler');
        $this->setHidden(true);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Restore indexes for GAR tables completed by a concurrent initial-load import.')
            ->addArgument('operation', InputArgument::REQUIRED, 'GAR import operation id.')
            ->addArgument('workers', InputArgument::REQUIRED, 'Maximum concurrent index workers.')
            ->addArgument('start-percent', InputArgument::REQUIRED, 'Completed source byte percentage before admission.')
            ->addArgument('persistent-workers', InputArgument::REQUIRED, 'Whether index workers persist across tasks (0|1).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $operation = $input->getArgument('operation');
            $workers = $input->getArgument('workers');
            $startPercent = $input->getArgument('start-percent');
            $persistentWorkers = $input->getArgument('persistent-workers');
            if (
                !\is_string($operation)
                || !\is_string($workers)
                || !\is_string($startPercent)
                || !\is_string($persistentWorkers)
                || \preg_match('/^[0-9]+$/D', $operation) !== 1
                || \preg_match('/^[0-9]+$/D', $workers) !== 1
                || \preg_match('/^[0-9]+$/D', $startPercent) !== 1
                || \preg_match('/^[01]$/D', $persistentWorkers) !== 1
            ) {
                throw new InvalidArgumentException('GAR index scheduler arguments must be unsigned integers.');
            }
            $this->scheduler->run(new RunGarIndexScheduler(
                (int) $operation,
                (int) $workers,
                (int) $startPercent,
                $persistentWorkers === '1',
            ));
        } catch (InvalidArgumentException|GarImportFailed $error) {
            $output->writeln('<error>' . $error->getMessage() . '</error>');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
