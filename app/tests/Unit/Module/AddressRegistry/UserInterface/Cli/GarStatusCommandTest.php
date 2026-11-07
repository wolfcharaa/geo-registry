<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry\UserInterface\Cli;

use App\Module\AddressRegistry\Capability\Status\AddressSearchRebuildStatus;
use App\Module\AddressRegistry\Capability\Status\GarImportStatus;
use App\Module\AddressRegistry\Capability\Status\GarRegionStatus;
use App\Module\AddressRegistry\Capability\Status\GarStatusReader;
use App\Module\AddressRegistry\Capability\Status\GarStatusSnapshot;
use App\Module\AddressRegistry\UserInterface\Cli\GarStatusCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Test;

#[Test]
final class GarStatusCommandTest
{
    public function testItNormalizesRegionsAndPrintsTheOperationalSnapshot(): void
    {
        $reader = new RecordingGarStatusReader();
        [$exitCode, $output] = self::run($reader, [
            'command' => 'gar:status',
            '--regions' => '77,50,77',
            '--exact' => true,
        ]);

        Assert::same($exitCode, 0);
        Assert::same($reader->regions, [50, 77]);
        Assert::true($reader->exact);
        Assert::true(str_contains($output, 'import=completed operation=7'));
        Assert::true(str_contains($output, 'rebuild=completed phase=completed operation=8'));
        Assert::true(str_contains($output, 'region=50 hierarchy=adm'));
        Assert::true(str_contains($output, 'projection_rows=100 geocoded=9 datamos=8 aliases=7'));
    }

    public function testItRejectsAnInvalidRegionBeforeReadingPostgres(): void
    {
        $reader = new RecordingGarStatusReader();
        [$exitCode, $output] = self::run($reader, [
            'command' => 'gar:status',
            '--regions' => '50,100',
        ]);

        Assert::same($exitCode, 2);
        Assert::same($reader->regions, []);
        Assert::true(str_contains($output, 'codes from 1 to 99'));
    }

    /**
     * @param array<string, bool|string> $arguments
     * @return array{int, string}
     */
    private static function run(GarStatusReader $reader, array $arguments): array
    {
        $application = new Application();
        $application->setAutoExit(false);
        $application->addCommand(new GarStatusCommand($reader));
        $output = new BufferedOutput();
        $exitCode = $application->run(new ArrayInput($arguments), $output);

        return [$exitCode, $output->fetch()];
    }
}

final class RecordingGarStatusReader implements GarStatusReader
{
    /** @var list<int> */
    public array $regions = [];

    public bool $exact = false;

    public function read(array $regions, bool $exact): GarStatusSnapshot
    {
        $this->regions = $regions;
        $this->exact = $exact;

        return new GarStatusSnapshot(
            garVersion: '2026.11.01',
            garVersionDate: '2026-11-01',
            import: new GarImportStatus(
                operationId: 7,
                status: 'completed',
                sourcePath: '/data/gar',
                manifestHash: str_repeat('a', 64),
                filesCompleted: 2,
                filesTotal: 2,
                rowsProcessed: 200,
                rowsMerged: 200,
                updatedAt: '2026-11-06 12:00:00+00',
                pendingFiles: 0,
                runningFiles: 0,
                completedFiles: 2,
                failedFiles: 0,
            ),
            rebuild: new AddressSearchRebuildStatus(
                operationId: 8,
                status: 'completed',
                phase: 'completed',
                tasksCompleted: 4,
                tasksTotal: 4,
                rowsScanned: 200,
                rowsWritten: 200,
                projectionRows: 200,
                updatedAt: '2026-11-06 12:01:00+00',
            ),
            regions: [new GarRegionStatus(
                regionCode: 50,
                hierarchy: 'adm',
                checkpointStatus: 'completed',
                checkpointRows: 100,
                projectionRows: 100,
                geocodedRows: 9,
                dataMosRows: 8,
                aliasRows: 7,
            )],
            exact: $exact,
        );
    }
}
