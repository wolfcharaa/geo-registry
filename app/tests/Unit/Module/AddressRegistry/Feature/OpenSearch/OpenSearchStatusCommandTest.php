<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry\Feature\OpenSearch;

use App\Module\AddressRegistry\Feature\OpenSearch\Capability\ProjectionStatus\OpenSearchProjectionStatusInspector;
use App\Module\AddressRegistry\Feature\OpenSearch\Capability\ProjectionStatus\OpenSearchProjectionStatusReport;
use App\Module\AddressRegistry\Feature\OpenSearch\UserInterface\Cli\OpenSearchStatusCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Test;

#[Test]
final class OpenSearchStatusCommandTest
{
    public function testItReportsHealthyParityAndInactiveIndexes(): void
    {
        [$exitCode, $output] = self::run(new OpenSearchProjectionStatusReport(
            alias: 'places',
            clusterStatus: 'yellow',
            clusterTimedOut: false,
            nodes: 1,
            dataNodes: 1,
            aliasTargets: ['places-v2'],
            sourceDocuments: 100,
            invalidRegionDocuments: 0,
            targetDocuments: 100,
            inactiveIndexes: ['places-v1'],
        ));

        Assert::same($exitCode, 0);
        Assert::true(str_contains($output, 'cluster status=yellow timed_out=no nodes=1 data_nodes=1'));
        Assert::true(str_contains($output, 'alias name=places targets=places-v2'));
        Assert::true(str_contains($output, 'parity source=100 target=100 invalid_regions=0 status=ok'));
        Assert::true(str_contains($output, 'inactive count=1 indexes=places-v1'));
        Assert::true(str_contains($output, 'summary status=ok'));
    }

    public function testItFailsClosedForAnAmbiguousAliasAndInvalidSourceRegions(): void
    {
        [$exitCode, $output] = self::run(new OpenSearchProjectionStatusReport(
            alias: 'places',
            clusterStatus: 'green',
            clusterTimedOut: false,
            nodes: 3,
            dataNodes: 2,
            aliasTargets: ['places-v1', 'places-v2'],
            sourceDocuments: 100,
            invalidRegionDocuments: 1,
            targetDocuments: null,
            inactiveIndexes: [],
        ));

        Assert::same($exitCode, 1);
        Assert::true(str_contains($output, 'targets=places-v1,places-v2'));
        Assert::true(str_contains($output, 'target=unavailable invalid_regions=1 status=failed'));
        Assert::true(str_contains($output, 'summary status=failed'));
    }

    /** @return array{int, string} */
    private static function run(OpenSearchProjectionStatusReport $report): array
    {
        $application = new Application();
        $application->setAutoExit(false);
        $application->addCommand(new OpenSearchStatusCommand(new FixedOpenSearchProjectionStatusInspector($report)));
        $output = new BufferedOutput();
        $exitCode = $application->run(new ArrayInput([
            'command' => 'opensearch:status',
            '--no-ansi' => true,
        ]), $output);

        return [$exitCode, $output->fetch()];
    }
}

final readonly class FixedOpenSearchProjectionStatusInspector implements OpenSearchProjectionStatusInspector
{
    public function __construct(private OpenSearchProjectionStatusReport $report) {}

    public function inspect(): OpenSearchProjectionStatusReport
    {
        return $this->report;
    }
}
