<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\DataMos\UserInterface\Cli;

use App\Module\AddressRegistry\Feature\DataMos\Infrastructure\DataMosAddressRegistryEnricher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'datamos:address-registry:enrich',
    description: 'Применяет staging Data.Mos к московской partition address_search.',
)]
final class EnrichAddressSearchFromDataMosCommand extends Command
{
    public function __construct(
        private readonly DataMosAddressRegistryEnricher $enricher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'no-insert-missing',
                null,
                InputOption::VALUE_NONE,
                'Не создавать datamos-only строки в address_search.',
            )
            ->setHelp(<<<'HELP'
Команда обновляет московские GAR-строки по N_FIAS и наследует Data.Mos metadata
для дочерних объектов. По умолчанию отсутствующие в GAR адреса добавляются как
datamos-only строки в региональную partition.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $result = $this->enricher->enrich($input->getOption('no-insert-missing') !== true);
        } catch (Throwable $error) {
            $output->writeln('<error>' . $error->getMessage() . '</error>');

            return self::FAILURE;
        }

        $output->writeln(sprintf(
            'status=enriched updated=%d inserted=%d elapsed_ms=%d',
            $result->updatedSearchRows,
            $result->insertedSearchRows,
            (int) round($result->duration * 1000),
        ));

        return self::SUCCESS;
    }
}
