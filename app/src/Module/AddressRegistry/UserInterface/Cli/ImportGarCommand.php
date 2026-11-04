<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\UserInterface\Cli;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use App\Module\AddressRegistry\Capability\ImportGar\ImportGar;
use App\Module\AddressRegistry\Capability\ImportGar\ImportGarRunner;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ImportGarCommand extends Command
{
    public function __construct(
        private readonly ImportGarRunner $runner,
    ) {
        parent::__construct('gar:import');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Import supported GAR XML files through bounded PostgreSQL COPY batches.')
            ->addArgument(
                'source',
                InputArgument::REQUIRED,
                'Path to one supported GAR XML file or a directory containing a GAR release.',
            )
            ->addOption(
                'batch-size',
                null,
                InputOption::VALUE_REQUIRED,
                'Rows per atomic COPY/merge/checkpoint batch (1..100000).',
                '5000',
            )
            ->addOption(
                'restart',
                null,
                InputOption::VALUE_NONE,
                'Abandon an unfinished checkpoint for this source and start a new operation.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $source = $input->getArgument('source');
            $batchSize = $input->getOption('batch-size');
            if (!is_string($source) || !is_string($batchSize) || preg_match('/^[0-9]+$/D', $batchSize) !== 1) {
                throw new InvalidArgumentException('source and --batch-size must have valid scalar values.');
            }

            $result = $this->runner->run(new ImportGar(
                sourcePath: $source,
                batchSize: (int) $batchSize,
                restart: $input->getOption('restart') === true,
            ));
        } catch (InvalidArgumentException|GarImportFailed $error) {
            $output->writeln('<error>' . $error->getMessage() . '</error>');

            return self::FAILURE;
        }

        $status = $result->alreadyCompleted ? 'already-completed' : 'completed';
        $output->writeln(sprintf(
            'status=%s operation=%d files=%d/%d rows=%d merged=%d resumed=%s',
            $status,
            $result->operationId,
            $result->filesCompleted,
            $result->filesTotal,
            $result->rowsProcessed,
            $result->rowsMerged,
            $result->resumed ? 'yes' : 'no',
        ));

        return self::SUCCESS;
    }
}
