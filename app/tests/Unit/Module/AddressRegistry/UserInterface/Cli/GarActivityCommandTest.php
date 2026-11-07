<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry\UserInterface\Cli;

use App\Module\AddressRegistry\Capability\Activity\GarActivityReader;
use App\Module\AddressRegistry\Capability\Activity\GarActivitySnapshot;
use App\Module\AddressRegistry\Capability\Activity\PostgresStatementActivity;
use App\Module\AddressRegistry\Capability\Activity\TargetOperationActivity;
use App\Module\AddressRegistry\Capability\Activity\WorkerInstanceActivity;
use App\Module\AddressRegistry\Capability\Activity\WorkerQueueActivity;
use App\Module\AddressRegistry\UserInterface\Cli\GarActivityCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Test;

#[Test]
final class GarActivityCommandTest
{
    public function testItPrintsTargetOwnedActivityWithoutLegacyParserLogs(): void
    {
        $reader = new FixedGarActivityReader(new GarActivitySnapshot(
            statements: [new PostgresStatementActivity(
                pid: 42,
                application: 'geo-registry',
                state: 'active',
                age: '00:00:03',
                waitEventType: 'IO',
                waitEvent: 'DataFileRead',
                query: 'SELECT * FROM address_search',
            )],
            operations: [new TargetOperationActivity(
                type: 'gar_import',
                operationId: 7,
                status: 'running',
                phase: null,
                stepsCompleted: 2,
                stepsTotal: 3,
                rowsRead: 100,
                rowsWritten: 90,
                updatedAt: '2026-11-07 10:00:00+03',
                error: null,
            )],
            workers: [new WorkerInstanceActivity(
                instanceId: 'worker-1',
                name: 'runtime',
                state: 'running',
                activity: 'idle',
                transport: 'postgres',
                queue: 'runtime',
                heartbeatAt: '2026-11-07 10:00:00+03',
                heartbeatAge: '00:00:01',
            )],
            queues: [new WorkerQueueActivity(
                flow: 'runtime_async',
                bindingId: 'gar.address_coordinates.update',
                status: 'pending',
                jobs: 4,
                oldestAt: '2026-11-07 09:59:00+03',
            )],
        ));

        [$exitCode, $output] = self::run($reader, [
            'command' => 'gar:activity',
            '--limit' => '5',
        ]);

        Assert::same($exitCode, 0);
        Assert::same($reader->limit, 5);
        Assert::true(str_contains($output, 'postgres_activity count=1'));
        Assert::true(str_contains($output, 'operation type=gar_import id=7 status=running'));
        Assert::true(str_contains($output, 'worker instance=worker-1'));
        Assert::true(str_contains($output, 'binding=gar.address_coordinates.update status=pending jobs=4'));
        Assert::false(str_contains($output, 'parser_logs'));
    }

    public function testItRejectsAnUnsafeLimitBeforeReadingPostgres(): void
    {
        $reader = new FixedGarActivityReader(new GarActivitySnapshot([], [], [], []));
        [$exitCode, $output] = self::run($reader, [
            'command' => 'gar:activity',
            '--limit' => '101',
        ]);

        Assert::same($exitCode, 2);
        Assert::same($reader->limit, null);
        Assert::true(str_contains($output, 'Option --limit must be an integer from 1 to 100.'));
    }

    /**
     * @param array<string, string> $arguments
     * @return array{int, string}
     */
    private static function run(GarActivityReader $reader, array $arguments): array
    {
        $application = new Application();
        $application->setAutoExit(false);
        $application->addCommand(new GarActivityCommand($reader));
        $output = new BufferedOutput();
        $exitCode = $application->run(new ArrayInput($arguments), $output);

        return [$exitCode, $output->fetch()];
    }
}

final class FixedGarActivityReader implements GarActivityReader
{
    public ?int $limit = null;

    public function __construct(private readonly GarActivitySnapshot $snapshot) {}

    public function read(int $limit): GarActivitySnapshot
    {
        $this->limit = $limit;

        return $this->snapshot;
    }
}
