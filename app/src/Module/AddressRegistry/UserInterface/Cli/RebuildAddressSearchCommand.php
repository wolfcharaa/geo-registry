<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\UserInterface\Cli;

use App\Module\AddressRegistry\Capability\RebuildAddressSearch\AddressSearchRebuildFailed;
use App\Module\AddressRegistry\Capability\RebuildAddressSearch\RebuildAddressSearch;
use App\Module\AddressRegistry\Capability\RebuildAddressSearch\RebuildAddressSearchRunner;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'gar:address-search:rebuild',
    description: 'Пересобирает региональную address_search через partitioned shadow table.',
)]
final class RebuildAddressSearchCommand extends Command
{
    public function __construct(
        private readonly RebuildAddressSearchRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'batch-size',
                null,
                InputOption::VALUE_REQUIRED,
                'Число hierarchy rows в одном атомарном region batch.',
                '50000',
            )
            ->addOption(
                'restart',
                null,
                InputOption::VALUE_NONE,
                'Отбросить незавершённый shadow checkpoint и начать новую операцию.',
            )
            ->addOption(
                'build-only',
                null,
                InputOption::VALUE_NONE,
                'Остановиться после indexes/parity в ready, не переключая active table.',
            )
            ->setHelp(<<<'HELP'
Команда строит новую LIST-partitioned address_search из canonical GAR tables.
Каждый region/hierarchy batch фиксирует данные и checkpoint одной транзакцией.
Active read path переключается атомарно только после integrity/parity checks.

Используйте --build-only, чтобы проверить ready shadow table до cutover. Повторный
запуск без этого флага продолжит ту же операцию и выполнит switch.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $batchSize = $input->getOption('batch-size');
        if (!is_string($batchSize) || !ctype_digit($batchSize)) {
            $output->writeln('<error>Option --batch-size must be a positive integer.</error>');

            return self::INVALID;
        }

        try {
            $result = $this->runner->run(new RebuildAddressSearch(
                batchSize: (int) $batchSize,
                restart: $input->getOption('restart') === true,
                buildOnly: $input->getOption('build-only') === true,
            ));
        } catch (InvalidArgumentException|AddressSearchRebuildFailed $error) {
            $output->writeln('<error>' . $error->getMessage() . '</error>');

            return self::FAILURE;
        }

        $output->writeln(sprintf(
            'status=%s operation=%d tasks=%d/%d scanned=%d written=%d rows=%d elapsed_ms=%d resumed=%s',
            $result->status,
            $result->operationId,
            $result->tasksCompleted,
            $result->tasksTotal,
            $result->rowsScanned,
            $result->rowsWritten,
            $result->shadowRows,
            $result->elapsedMilliseconds,
            $result->resumed ? 'yes' : 'no',
        ));

        return self::SUCCESS;
    }
}
