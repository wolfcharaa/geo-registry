<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\UserInterface\Cli;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use App\Module\AddressRegistry\Capability\ImportGar\GarImportPlanner;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class PlanGarImportCommand extends Command
{
    public function __construct(private readonly GarImportPlanner $planner)
    {
        parent::__construct('gar:import:plan');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Inspect a GAR manifest and list eligible batch-pipeline XML files without importing.')
            ->addArgument('source', InputArgument::REQUIRED, 'Path to a GAR XML file or release directory.')
            ->addOption(
                'regions',
                null,
                InputOption::VALUE_REQUIRED,
                'Inspect shared dictionaries and only these regional directories (codes 1..99).',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $source = $input->getArgument('source');
            $regions = $input->getOption('regions');
            if (!\is_string($source) || ($regions !== null && !\is_string($regions))) {
                throw new InvalidArgumentException('source and --regions must have valid scalar values.');
            }
            $selectedRegions = $regions === null ? [] : self::regions($regions);
            $plan = $this->planner->plan($source, $selectedRegions);
        } catch (InvalidArgumentException|GarImportFailed $error) {
            $output->writeln('<error>' . $error->getMessage() . '</error>');

            return self::FAILURE;
        }

        $output->writeln(\sprintf(
            'manifest=%s files=%d bytes=%d regions=%s pipeline_candidates=%d',
            $plan->manifestHash,
            $plan->filesTotal,
            $plan->bytesTotal,
            $selectedRegions === [] ? 'all' : \implode(',', $selectedRegions),
            \count($plan->pipelineCandidates),
        ));
        foreach ($plan->pipelineCandidates as $candidate) {
            $output->writeln(\sprintf(
                'pipeline_candidate path="%s" bytes=%d priority=%d auto=%s',
                self::quoted($candidate->relativePath),
                $candidate->size,
                $candidate->priority,
                $candidate->automatic ? 'yes' : 'no',
            ));
        }

        return self::SUCCESS;
    }

    /** @return non-empty-list<int> */
    private static function regions(string $value): array
    {
        $regions = [];
        foreach (\explode(',', $value) as $part) {
            $part = \trim($part);
            if (\preg_match('/^[0-9]{1,2}$/D', $part) !== 1 || (int) $part < 1 || (int) $part > 99) {
                throw new InvalidArgumentException('Option --regions accepts unique codes from 1 to 99.');
            }
            $region = (int) $part;
            if (isset($regions[$region])) {
                throw new InvalidArgumentException('Option --regions accepts unique codes from 1 to 99.');
            }
            $regions[$region] = $region;
        }
        \ksort($regions);

        return \array_values($regions);
    }

    private static function quoted(string $value): string
    {
        return \str_replace(["\\", '"', "\r", "\n"], ["\\\\", '\\"', '\\r', '\\n'], $value);
    }
}
