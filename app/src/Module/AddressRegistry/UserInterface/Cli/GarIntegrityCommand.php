<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\UserInterface\Cli;

use App\Module\AddressRegistry\Capability\Integrity\GarIntegrityChecker;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'gar:integrity',
    description: 'Проверяет manifests, checkpoints и региональную целостность GAR.',
)]
final class GarIntegrityCommand extends Command
{
    public function __construct(private readonly GarIntegrityChecker $checker)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('regions', null, InputOption::VALUE_REQUIRED, 'Коды регионов через запятую.', '50,77')
            ->addOption(
                'deep',
                null,
                InputOption::VALUE_NONE,
                'Добавить точные проверки projection, GAR ownership и aliases.',
            )
            ->addOption('only-failed', null, InputOption::VALUE_NONE, 'Не печатать успешные проверки.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $regions = $input->getOption('regions');
        if (!is_string($regions)) {
            $output->writeln('<error>Option --regions must be a comma-separated list.</error>');

            return self::INVALID;
        }
        try {
            $regions = self::regions($regions);
        } catch (InvalidArgumentException $error) {
            $output->writeln('<error>' . $error->getMessage() . '</error>');

            return self::INVALID;
        }

        $report = $this->checker->check($regions, $input->getOption('deep') === true);
        $onlyFailed = $input->getOption('only-failed') === true;
        $output->writeln(sprintf(
            'mode=%s regions=%s',
            $report->deep ? 'deep' : 'quick',
            implode(',', $report->regions),
        ));
        foreach ($report->checks as $check) {
            if ($onlyFailed && $check->isValid()) {
                continue;
            }
            $output->writeln(match (true) {
                !$check->isAvailable() => "ERROR check=\"{$check->name}\" issues=unavailable",
                !$check->isValid() => "FAIL check=\"{$check->name}\" issues={$check->issues}",
                default => "OK check=\"{$check->name}\" issues=0",
            });
        }
        $output->writeln(sprintf(
            'summary checks=%d failed=%d unavailable=%d status=%s',
            count($report->checks),
            $report->failedChecks(),
            $report->unavailableChecks(),
            $report->isValid() ? 'ok' : 'failed',
        ));

        return $report->isValid() ? self::SUCCESS : self::FAILURE;
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
            $regions[(int) $part] = (int) $part;
        }
        ksort($regions);

        return array_values($regions);
    }
}
