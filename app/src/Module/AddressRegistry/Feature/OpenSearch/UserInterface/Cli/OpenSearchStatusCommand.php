<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\OpenSearch\UserInterface\Cli;

use App\Module\AddressRegistry\Feature\OpenSearch\Capability\OpenSearchProjectionFailed;
use App\Module\AddressRegistry\Feature\OpenSearch\Capability\ProjectionStatus\OpenSearchProjectionStatusInspector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'opensearch:status',
    description: 'Проверяет cluster, read alias и parity OpenSearch projection.',
)]
final class OpenSearchStatusCommand extends Command
{
    public function __construct(private readonly OpenSearchProjectionStatusInspector $inspector)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $report = $this->inspector->inspect();
        } catch (OpenSearchProjectionFailed $error) {
            $output->writeln('<error>' . $error->getMessage() . '</error>');

            return self::FAILURE;
        }

        $output->writeln(sprintf(
            'cluster status=%s timed_out=%s nodes=%d data_nodes=%d',
            $report->clusterStatus,
            $report->clusterTimedOut ? 'yes' : 'no',
            $report->nodes,
            $report->dataNodes,
        ));
        $output->writeln(sprintf(
            'alias name=%s targets=%s',
            $report->alias,
            $report->aliasTargets === [] ? 'none' : implode(',', $report->aliasTargets),
        ));
        $output->writeln(sprintf(
            'parity source=%d target=%s invalid_regions=%d status=%s',
            $report->sourceDocuments,
            $report->targetDocuments === null ? 'unavailable' : (string) $report->targetDocuments,
            $report->invalidRegionDocuments,
            $report->targetDocuments === $report->sourceDocuments && $report->invalidRegionDocuments === 0
                ? 'ok'
                : 'failed',
        ));
        $output->writeln(sprintf(
            'inactive count=%d indexes=%s',
            count($report->inactiveIndexes),
            $report->inactiveIndexes === [] ? 'none' : implode(',', $report->inactiveIndexes),
        ));
        $output->writeln('summary status=' . ($report->isHealthy() ? 'ok' : 'failed'));

        return $report->isHealthy() ? self::SUCCESS : self::FAILURE;
    }
}
