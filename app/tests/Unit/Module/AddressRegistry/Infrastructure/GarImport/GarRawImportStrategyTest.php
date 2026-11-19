<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Infrastructure\GarImport\GarRawDiagnosticMapper;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarRawFileAdmissionQueue;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarRawImportStrategy;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarRawRangeTask;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarRawRangeTaskHandler;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlByteRange;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlCatalog;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlSourceFile;
use Fedor\WorkOrchestrator\Event\EventContext;
use Fedor\WorkOrchestrator\Event\ExecutorType;
use Fedor\WorkOrchestrator\Event\ProcessRole;
use Fedor\WorkOrchestrator\Result\FailureDiagnostic;
use Fedor\WorkOrchestrator\Result\FailureOrigin;
use Fedor\WorkOrchestrator\Result\TaskOutcome;
use Fedor\WorkOrchestrator\Scheduling\Granted;
use Fedor\WorkOrchestrator\WorkId;
use LogicException;
use RuntimeException;
use Testo\Assert;
use Testo\Test;

#[Test]
final class GarRawImportStrategyTest
{
    public function testAdmissionKeepsDependencyPhasesAndLargestFirstOrder(): void
    {
        $files = new GarXmlCatalog()->manifest(\dirname(__DIR__, 5) . '/Fixture/Gar/regions', [50])->files;
        $queue = GarRawFileAdmissionQueue::create(\array_reverse($files));
        $admitted = [];

        while (($decision = $queue->acquire()) instanceof Granted) {
            $file = $decision->lease->work->payload;
            if (!$file instanceof GarXmlSourceFile) {
                throw new \LogicException('GAR raw queue returned an invalid payload.');
            }
            $admitted[] = [$file->descriptor->priority, $file->size, $file->relativePath];
            $queue->complete($decision->lease, TaskOutcome::succeeded(null));
        }

        $expected = $admitted;
        \usort($expected, static fn(array $left, array $right): int => [
            $left[0],
            -$left[1],
            $left[2],
        ] <=> [
            $right[0],
            -$right[1],
            $right[2],
        ]);

        Assert::same($admitted, $expected);
    }

    public function testStrategyAndRangeLocatorStaySerializableWithoutBulkRows(): void
    {
        $file = new GarXmlCatalog()
            ->manifest(\dirname(__DIR__, 5) . '/Fixture/Gar/regions', [50])
            ->files[0];
        $strategy = new GarRawImportStrategy(
            databaseDsn: 'host=postgres',
            operationId: 42,
            batchSize: 5_000,
            pipelineFileKeys: [$file->key],
            maxInFlightRanges: 2,
            maxFragmentBytes: 8 * 1024 * 1024,
        );
        $task = new GarRawRangeTask(
            operationId: 42,
            file: $file,
            range: new GarXmlByteRange(10, 110, 1, 2),
            maxEncodedBytes: 8 * 1024 * 1024,
        );
        $serializedTask = \serialize($task);

        Assert::true(\unserialize(\serialize($strategy)) instanceof GarRawImportStrategy);
        Assert::same($task->handler(), GarRawRangeTaskHandler::class);
        Assert::true(\strlen($serializedTask) < 16 * 1024);
        Assert::false(\str_contains($serializedTask, '<OBJECT'));
    }

    public function testItMapsStructuredChildFailureEvidenceWithoutPackageTypes(): void
    {
        $context = new EventContext(
            runId: 'run-1',
            workId: new WorkId('/data/gar/AS_REESTR_OBJECTS.XML'),
            taskId: 'range-7',
            parentTaskId: 'file-1',
            role: ProcessRole::Child,
            depth: 2,
            sourceId: 'child-1',
        );
        $diagnostic = FailureDiagnostic::from(
            new LogicException('parse failed', previous: new RuntimeException('malformed XML')),
            FailureOrigin::task($context, ExecutorType::Parallel, 2),
        );

        $mapped = GarRawDiagnosticMapper::map($diagnostic);

        Assert::same($mapped->kind, 'application');
        Assert::same($mapped->role, 'child');
        Assert::same($mapped->executor, 'parallel');
        Assert::same($mapped->workId, '/data/gar/AS_REESTR_OBJECTS.XML');
        Assert::same($mapped->taskId, 'range-7');
        Assert::same($mapped->attempt, 2);
        Assert::same($mapped->type, LogicException::class);
        Assert::same($mapped->message, 'parse failed');
        Assert::count($mapped->causes, 1);
        $cause = $mapped->causes[0]
            ?? throw new LogicException('Expected mapped previous-cause evidence.');
        Assert::same($cause->type, RuntimeException::class);
        Assert::same($cause->message, 'malformed XML');
    }
}
