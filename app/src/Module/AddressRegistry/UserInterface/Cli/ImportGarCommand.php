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
                '50000',
            )
            ->addOption(
                'workers',
                null,
                InputOption::VALUE_REQUIRED,
                'Parallel GAR file worker processes per dependency phase (1..16).',
                '2',
            )
            ->addOption(
                'regions',
                null,
                InputOption::VALUE_REQUIRED,
                'Import shared dictionaries and only these regional directories (comma-separated codes 1..99).',
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
            $workers = $input->getOption('workers');
            $regions = $input->getOption('regions');
            if (
                !is_string($source)
                || !is_string($batchSize)
                || !is_string($workers)
                || ($regions !== null && !is_string($regions))
                || preg_match('/^[0-9]+$/D', $batchSize) !== 1
                || preg_match('/^[0-9]+$/D', $workers) !== 1
            ) {
                throw new InvalidArgumentException('source, --batch-size and --workers must have valid scalar values.');
            }

            $workerCount = (int) $workers;
            $selectedRegions = $regions === null ? [] : self::regions($regions);
            $result = $this->runner->run(new ImportGar(
                sourcePath: $source,
                batchSize: (int) $batchSize,
                workers: $workerCount,
                restart: $input->getOption('restart') === true,
                regions: $selectedRegions,
            ));
        } catch (InvalidArgumentException|GarImportFailed $error) {
            $output->writeln('<error>' . $error->getMessage() . '</error>');

            return self::FAILURE;
        }

        $status = $result->alreadyCompleted ? 'already-completed' : 'completed';
        $output->writeln(sprintf(
            'status=%s operation=%d files=%d/%d rows=%d merged=%d workers=%d regions=%s resumed=%s',
            $status,
            $result->operationId,
            $result->filesCompleted,
            $result->filesTotal,
            $result->rowsProcessed,
            $result->rowsMerged,
            $workerCount,
            $selectedRegions === [] ? 'all' : implode(',', $selectedRegions),
            $result->resumed ? 'yes' : 'no',
        ));

        return self::SUCCESS;
    }

    /** @return non-empty-list<int> */
    private static function regions(string $value): array
    {
        $regions = [];
        foreach (explode(',', $value) as $part) {
            $part = trim($part);
            if (preg_match('/^[0-9]{1,2}$/D', $part) !== 1 || (int) $part < 1 || (int) $part > 99) {
                throw new InvalidArgumentException('Option --regions accepts unique codes from 1 to 99.');
            }
            $region = (int) $part;
            if (isset($regions[$region])) {
                throw new InvalidArgumentException('Option --regions accepts unique codes from 1 to 99.');
            }
            $regions[$region] = $region;
        }
        ksort($regions);

        return array_values($regions);
    }
}
