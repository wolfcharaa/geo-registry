<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\UserInterface\Cli;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use App\Module\AddressRegistry\Capability\ImportGar\RunGarForeignKeyValidationWorker;
use App\Module\AddressRegistry\Capability\ImportGar\RunGarForeignKeyValidationWorkerPort;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RunGarForeignKeyValidationWorkerCommand extends Command
{
    public function __construct(private readonly RunGarForeignKeyValidationWorkerPort $worker)
    {
        parent::__construct('gar:import:foreign-key-worker');
        $this->setHidden(true);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Validate one restored GAR foreign key for an initial-load operation.')
            ->addArgument('operation', InputArgument::REQUIRED, 'GAR import operation id.')
            ->addArgument('table', InputArgument::REQUIRED, 'Snapshot table name.')
            ->addArgument('constraint', InputArgument::REQUIRED, 'Snapshot constraint name.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $operation = $input->getArgument('operation');
            $table = $input->getArgument('table');
            $constraint = $input->getArgument('constraint');
            if (
                !\is_string($operation)
                || !\is_string($table)
                || !\is_string($constraint)
                || \preg_match('/^[0-9]+$/D', $operation) !== 1
            ) {
                throw new InvalidArgumentException(
                    'GAR foreign-key worker arguments must have valid scalar values.',
                );
            }
            $this->worker->run(new RunGarForeignKeyValidationWorker(
                (int) $operation,
                $table,
                $constraint,
            ));
        } catch (InvalidArgumentException|GarImportFailed $error) {
            $output->writeln('<error>' . $error->getMessage() . '</error>');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
