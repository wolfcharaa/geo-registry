<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\OpenSearch\UserInterface\Cli;

use App\Module\AddressRegistry\Feature\OpenSearch\Capability\CleanupProjection\CleanupOpenSearchIndex;
use App\Module\AddressRegistry\Feature\OpenSearch\Capability\CleanupProjection\CleanupOpenSearchIndexRunner;
use App\Module\AddressRegistry\Feature\OpenSearch\Capability\OpenSearchProjectionFailed;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'opensearch:index:cleanup',
    description: 'Удаляет неактивный versioned OpenSearch index с точным подтверждением.',
)]
final class CleanupOpenSearchIndexCommand extends Command
{
    public function __construct(
        private readonly CleanupOpenSearchIndexRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('index', null, InputOption::VALUE_REQUIRED, 'Точное имя versioned physical index.')
            ->addOption(
                'confirm',
                null,
                InputOption::VALUE_REQUIRED,
                'Повтор точного имени index; active alias target удалить нельзя.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $index = $input->getOption('index');
        $confirmation = $input->getOption('confirm');
        if (!is_string($index) || !is_string($confirmation)) {
            $output->writeln('<error>Options --index and --confirm are required.</error>');

            return self::INVALID;
        }

        try {
            $result = $this->runner->run(new CleanupOpenSearchIndex($index, $confirmation));
        } catch (InvalidArgumentException|OpenSearchProjectionFailed $error) {
            $output->writeln('<error>' . $error->getMessage() . '</error>');

            return self::FAILURE;
        }

        $output->writeln("status=deleted index={$result->deletedIndex}");

        return self::SUCCESS;
    }
}
