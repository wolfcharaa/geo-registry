<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\OpenSearch\UserInterface\Cli;

use App\Module\AddressRegistry\Feature\OpenSearch\Capability\OpenSearchProjectionFailed;
use App\Module\AddressRegistry\Feature\OpenSearch\Capability\RebuildProjection\RebuildOpenSearchProjection;
use App\Module\AddressRegistry\Feature\OpenSearch\Capability\RebuildProjection\RebuildOpenSearchProjectionRunner;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'opensearch:reindex',
    description: 'Строит versioned OpenSearch index и атомарно переключает read alias.',
)]
final class RebuildOpenSearchProjectionCommand extends Command
{
    public function __construct(
        private readonly RebuildOpenSearchProjectionRunner $runner,
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
                'Число документов в одном bounded NDJSON _bulk request; 1..10000.',
                '1000',
            )
            ->addOption(
                'concurrency',
                null,
                InputOption::VALUE_REQUIRED,
                'Максимальное число одновременно выполняемых _bulk requests; 1..32.',
                '4',
            )
            ->addOption(
                'no-alias-switch',
                null,
                InputOption::VALUE_NONE,
                'Остановиться после parity check, не переключая read alias.',
            )
            ->setHelp(<<<'HELP'
Команда читает address_search/address_aliases из согласованного PostgreSQL snapshot,
пишет документы в новый versioned physical index и проверяет source/index parity.
Read alias переключается одной атомарной OpenSearch operation только после успеха.

Неявных retry нет. При ошибке active alias не меняется, а незавершённый target
остаётся для диагностики и удаляется отдельной подтверждённой командой
opensearch:index:cleanup.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $result = $this->runner->run(new RebuildOpenSearchProjection(
                batchSize: self::integerOption($input, 'batch-size'),
                concurrency: self::integerOption($input, 'concurrency'),
                switchAlias: $input->getOption('no-alias-switch') !== true,
            ));
        } catch (InvalidArgumentException|OpenSearchProjectionFailed $error) {
            $output->writeln('<error>' . $error->getMessage() . '</error>');

            return self::FAILURE;
        }

        $output->writeln(sprintf(
            'status=completed target=%s source=%d indexed=%d target_documents=%d alias_switched=%s previous=%s elapsed_ms=%d',
            $result->targetIndex,
            $result->sourceDocuments,
            $result->indexedDocuments,
            $result->targetDocuments,
            $result->aliasSwitched ? 'yes' : 'no',
            $result->previousIndexes === [] ? 'none' : implode(',', $result->previousIndexes),
            $result->elapsedMilliseconds,
        ));

        return self::SUCCESS;
    }

    private static function integerOption(InputInterface $input, string $name): int
    {
        $value = $input->getOption($name);
        if (!is_string($value) || !ctype_digit($value)) {
            throw new InvalidArgumentException("Option --{$name} must be a positive integer.");
        }

        return (int) $value;
    }
}
