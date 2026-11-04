<?php

declare(strict_types=1);

namespace App\Tests\Integration\Application\Worker;

use Amp\Postgres\PostgresConnectionPool;
use App\Application\DependencyInjection\ApplicationMessageBusModule;
use App\Application\DependencyInjection\WorkerRuntimeModule;
use App\Application\MessageBus\MessageBusRegistrySource;
use App\Application\Observability\CorrelationContext;
use App\Config\EnvironmentSnapshot;
use App\Infrastructure\Postgres\PostgresPoolFactory;
use App\Tests\Support\Database\IntegrationDatabase;
use Fedor\MessageBus\Attribute\CommandHandler;
use Fedor\MessageBus\Attribute\MessageAlias;
use Fedor\MessageBus\Codec\JsonValue;
use Fedor\MessageBus\Codec\MessageCodecInterface;
use Fedor\MessageBus\Codec\MessageCodecRegistry;
use Fedor\MessageBus\Exception\NonRetryableMessageExceptionInterface;
use Fedor\MessageBus\Flow\FlowDefinition;
use Fedor\MessageBus\Flow\FlowRegistry;
use Fedor\MessageBus\Message\Command;
use Fedor\MessageBus\Message\Event;
use Fedor\MessageBus\Message\Query;
use Fedor\MessageBus\MessageBus;
use Fedor\MessageBus\Worker\Control\WorkerControlRequest;
use Fedor\MessageBus\Worker\Control\WorkerTarget;
use Fedor\MessageBus\Worker\Queue\ConsumerOptions;
use Fedor\MessageBus\Worker\Queue\QueueWorkerRunnerOptions;
use Fedor\MessageBus\Worker\Runtime\WorkerRuntime;
use Psr\Log\NullLogger;
use RuntimeException;
use Testo\Assert;
use Testo\Test;
use Thesis\Dic;
use Thesis\Dic\Module;
use Thesis\Dic\Ref;

#[Test]
final class ApplicationWorkerRuntimeIntegrationTest
{
    public function testItRunsTheDurableLifecycleOnRealPostgres(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $database = PostgresPoolFactory::create($dsn, 1);

        try {
            IntegrationDatabase::resetSchema($database);
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', ['--force-yes' => true]);
            WorkerLifecycleHandler::reset();

            Dic::run(
                new WorkerLifecycleModule($dsn),
                static function (WorkerLifecycleHarness $harness): void {
                    self::assertSuccessfulDelivery($harness);
                    self::assertRetryAndReject($harness);
                    self::assertStaleRecovery($harness);
                    self::assertIdempotentGracefulStop($harness);
                },
            );
        } finally {
            IntegrationDatabase::resetSchema($database);
            $database->close();
        }
    }

    private static function assertSuccessfulDelivery(WorkerLifecycleHarness $harness): void
    {
        $jobId = $harness->enqueue(new WorkerLifecycleCommand('success-1', 'succeed'));
        $result = $harness->runOne('success-worker');

        Assert::same([$result->handled, $result->succeeded, $result->retried, $result->rejected], [1, 1, 0, 0]);
        Assert::same($harness->status($jobId), 'succeeded');
        Assert::same(WorkerLifecycleHandler::$handled, ['success-1']);
    }

    private static function assertRetryAndReject(WorkerLifecycleHarness $harness): void
    {
        $retryJob = $harness->enqueue(new WorkerLifecycleCommand('retry-1', 'retry_once'));
        $retried = $harness->runOne('retry-worker-1');

        Assert::same([$retried->handled, $retried->retried], [1, 1]);
        Assert::same($harness->status($retryJob), 'pending');

        $harness->makeAvailable($retryJob);
        $succeeded = $harness->runOne('retry-worker-2');

        Assert::same([$succeeded->handled, $succeeded->succeeded], [1, 1]);
        Assert::same($harness->status($retryJob), 'succeeded');
        Assert::same(WorkerLifecycleHandler::$attempts['retry-1'] ?? null, 2);

        $rejectedJob = $harness->enqueue(new WorkerLifecycleCommand('reject-1', 'reject'));
        $rejected = $harness->runOne('reject-worker');

        Assert::same([$rejected->handled, $rejected->rejected, $rejected->retried], [1, 1, 0]);
        Assert::same($harness->status($rejectedJob), 'failed');
    }

    private static function assertStaleRecovery(WorkerLifecycleHarness $harness): void
    {
        $jobId = $harness->enqueue(new WorkerLifecycleCommand('stale-1', 'succeed'));
        $harness->makeStale($jobId);

        $result = $harness->runUntilEmpty('stale-recovery-worker', lockTtlSeconds: 1);

        Assert::same([$result->handled, $result->succeeded], [0, 0]);
        Assert::same($harness->status($jobId), 'pending');
        Assert::true(str_contains($harness->lastError($jobId), 'heartbeat expired'));
        $harness->cancel($jobId);
    }

    private static function assertIdempotentGracefulStop(WorkerLifecycleHarness $harness): void
    {
        $jobId = $harness->enqueue(new WorkerLifecycleCommand('graceful-1', 'succeed'));
        $target = new WorkerTarget(workerGroup: 'shutdown-test');
        $request = new WorkerControlRequest(
            source: 'integration-test',
            idempotencyKey: 'worker-stop-w1',
        );

        $first = $harness->runtime->requireControl()->controlService->stop($target, $request);
        $duplicate = $harness->runtime->requireControl()->controlService->stop($target, $request);
        $result = $harness->runUntilEmpty('graceful-worker', workerGroup: 'shutdown-test');

        Assert::false($first->duplicate);
        Assert::true($duplicate->duplicate);
        Assert::same($duplicate->commandId, $first->commandId);
        Assert::same($result->handled, 0);
        Assert::same($harness->status($jobId), 'pending');
        Assert::same($harness->workerState('graceful-worker'), 'stopped');
    }
}

/** @implements Module<Ref<WorkerLifecycleHarness>> */
final readonly class WorkerLifecycleModule implements Module
{
    public function __construct(private string $dsn) {}

    /** @return Ref<WorkerLifecycleHarness> */
    public function configure(Dic $dic): Ref
    {
        $source = new MessageBusRegistrySource(
            classes: [WorkerLifecycleHandler::class],
            services: [WorkerLifecycleHandler::class => WorkerLifecycleHandler::class],
            flows: new FlowRegistry(
                FlowDefinition::async('runtime_async')->transport('postgres', 'runtime'),
            ),
        );
        $codecs = new MessageCodecRegistry([new WorkerLifecycleCodec()]);
        $environment = new EnvironmentSnapshot([
            'DATABASE_DSN' => $this->dsn,
            'DATABASE_POOL_SIZE' => '2',
            'LOG_FORMAT' => 'json',
        ]);
        $postgres = $dic
            ->object(
                PostgresConnectionPool::class,
                fn(): PostgresConnectionPool => PostgresPoolFactory::create($this->dsn, 2),
            )
            ->disposer(static fn(PostgresConnectionPool $pool) => $pool->close());
        $bus = $dic->import(new ApplicationMessageBusModule(
            $source,
            $dic->object(CorrelationContext::class),
            $dic->object(NullLogger::class),
            postgres: $postgres,
            messageCodecs: $codecs,
        ));
        $runtime = $dic->import(new WorkerRuntimeModule($environment, $source, $codecs));

        return $dic
            ->object(WorkerLifecycleHarness::class)
            ->args([
                'bus' => $bus,
                'runtime' => $runtime,
                'postgres' => $postgres,
            ]);
    }
}

final readonly class WorkerLifecycleHarness
{
    public function __construct(
        private MessageBus $bus,
        public WorkerRuntime $runtime,
        private PostgresConnectionPool $postgres,
    ) {}

    public function enqueue(WorkerLifecycleCommand $command): string
    {
        $this->bus->dispatch($command);
        $row = $this->postgres
            ->query('SELECT id FROM message_bus__queue_jobs ORDER BY id DESC LIMIT 1')
            ->fetchRow();
        $id = $row['id'] ?? null;
        if (!is_int($id) && !is_string($id)) {
            throw new RuntimeException('Enqueued queue job has no identifier.');
        }

        return (string) $id;
    }

    public function runOne(string $workerName): \Fedor\MessageBus\Worker\Queue\QueueWorkerRunResult
    {
        return $this->runtime->runner->run(
            $this->consumer($workerName),
            $this->runner($workerName, maxMessages: 1),
        );
    }

    public function runUntilEmpty(
        string $workerName,
        int $lockTtlSeconds = 300,
        string $workerGroup = 'lifecycle-test',
    ): \Fedor\MessageBus\Worker\Queue\QueueWorkerRunResult {
        return $this->runtime->runner->run(
            $this->consumer($workerName, $lockTtlSeconds),
            $this->runner($workerName, workerGroup: $workerGroup),
        );
    }

    public function status(string $jobId): string
    {
        return $this->stringField($this->job($jobId), 'status');
    }

    public function lastError(string $jobId): string
    {
        return $this->stringField($this->job($jobId), 'last_error');
    }

    public function workerState(string $workerName): string
    {
        $row = $this->postgres->execute(
            'SELECT state FROM message_bus__worker_instances
             WHERE worker_name = :worker_name ORDER BY started_at DESC LIMIT 1',
            ['worker_name' => $workerName],
        )->fetchRow();
        if ($row === null) {
            throw new RuntimeException('Worker instance was not registered.');
        }

        return $this->stringField($row, 'state');
    }

    public function makeAvailable(string $jobId): void
    {
        $this->postgres->execute(
            "UPDATE message_bus__queue_jobs
             SET available_at = NOW() - INTERVAL '1 second', updated_at = NOW()
             WHERE id = :id",
            ['id' => $jobId],
        );
    }

    public function makeStale(string $jobId): void
    {
        $this->postgres->execute(
            <<<'SQL'
UPDATE message_bus__queue_jobs
SET status = 'running',
    attempts = 1,
    locked_at = NOW() - INTERVAL '10 seconds',
    heartbeat_at = NOW() - INTERVAL '10 seconds',
    locked_by = 'dead-worker',
    started_at = NOW() - INTERVAL '10 seconds',
    updated_at = NOW() - INTERVAL '10 seconds'
WHERE id = :id
SQL,
            ['id' => $jobId],
        );
    }

    public function cancel(string $jobId): void
    {
        $this->postgres->execute(
            "UPDATE message_bus__queue_jobs SET status = 'cancelled', finished_at = NOW() WHERE id = :id",
            ['id' => $jobId],
        );
    }

    private function consumer(string $workerName, int $lockTtlSeconds = 300): ConsumerOptions
    {
        return new ConsumerOptions(
            transport: 'postgres',
            queue: 'runtime',
            timeoutSeconds: 0,
            workerId: $workerName,
            lockTtlSeconds: $lockTtlSeconds,
            flows: ['runtime_async'],
        );
    }

    private function runner(
        string $workerName,
        ?int $maxMessages = null,
        string $workerGroup = 'lifecycle-test',
    ): QueueWorkerRunnerOptions {
        return new QueueWorkerRunnerOptions(
            maxMessages: $maxMessages,
            stopWhenEmpty: true,
            sleepWhenIdleMilliseconds: 1,
            workerName: $workerName,
            workerGroup: $workerGroup,
            heartbeatIntervalMilliseconds: 10,
            cancellationPollIntervalMilliseconds: 10,
            controlPollIntervalMilliseconds: 10,
        );
    }

    /** @return array<string, mixed> */
    private function job(string $jobId): array
    {
        $row = $this->postgres->execute(
            'SELECT status, last_error FROM message_bus__queue_jobs WHERE id = :id',
            ['id' => $jobId],
        )->fetchRow();

        return $row ?? throw new RuntimeException('Queue job was not found.');
    }

    /** @param array<string, mixed> $row */
    private function stringField(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException(sprintf('PostgreSQL field `%s` is not a string.', $field));
        }

        return $value;
    }
}

#[MessageAlias('geo-registry.test.worker-lifecycle.v1')]
final readonly class WorkerLifecycleCommand implements Command
{
    public function __construct(
        public string $operationId,
        public string $behavior,
    ) {}
}

#[CommandHandler(
    message: WorkerLifecycleCommand::class,
    flow: 'runtime_async',
    bindingId: 'geo-registry.test.worker-lifecycle.v1',
    contextAware: false,
)]
final class WorkerLifecycleHandler
{
    /** @var array<string, int> */
    public static array $attempts = [];

    /** @var list<string> */
    public static array $handled = [];

    public static function reset(): void
    {
        self::$attempts = [];
        self::$handled = [];
    }

    public function __invoke(WorkerLifecycleCommand $command): void
    {
        $attempt = self::$attempts[$command->operationId] = (self::$attempts[$command->operationId] ?? 0) + 1;
        if ($command->behavior === 'retry_once' && $attempt === 1) {
            throw new RuntimeException('Retryable integration failure.');
        }
        if ($command->behavior === 'reject') {
            throw new WorkerLifecycleNonRetryable('Non-retryable integration failure.');
        }

        self::$handled[] = $command->operationId;
    }
}

final class WorkerLifecycleNonRetryable extends RuntimeException implements NonRetryableMessageExceptionInterface {}

final class WorkerLifecycleCodec implements MessageCodecInterface
{
    public function messageClass(): string
    {
        return WorkerLifecycleCommand::class;
    }

    public function alias(): string
    {
        return 'geo-registry.test.worker-lifecycle.v1';
    }

    public function version(): int
    {
        return 1;
    }

    /** @param Query<mixed>|Command|Event $message */
    public function toPayload(Query|Command|Event $message): JsonValue
    {
        if (!$message instanceof WorkerLifecycleCommand) {
            throw new RuntimeException('Unexpected worker lifecycle message.');
        }

        return JsonValue::from([
            'operationId' => $message->operationId,
            'behavior' => $message->behavior,
        ]);
    }

    public function fromPayload(JsonValue $payload): WorkerLifecycleCommand
    {
        $data = $payload->value();
        if (!is_array($data)) {
            throw new RuntimeException('Worker lifecycle payload must be an object.');
        }
        $operationId = $data['operationId'] ?? null;
        $behavior = $data['behavior'] ?? null;
        if (!is_string($operationId) || !is_string($behavior)) {
            throw new RuntimeException('Worker lifecycle payload fields must be strings.');
        }

        return new WorkerLifecycleCommand($operationId, $behavior);
    }
}
