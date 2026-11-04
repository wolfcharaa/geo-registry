<?php

declare(strict_types=1);

namespace App\Tests\Scenario\Application\MessageBus;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use App\Application\DependencyInjection\ApplicationMessageBusModule;
use App\Application\MessageBus\MessageBusRegistrySource;
use App\Application\Observability\CorrelationContext;
use App\Application\Observability\CorrelationIds;
use Fedor\MessageBus\Attribute\CommandHandler;
use Fedor\MessageBus\Attribute\QueryHandler;
use Fedor\MessageBus\Context\MessageContextInterface;
use Fedor\MessageBus\Envelope\Envelope;
use Fedor\MessageBus\Execution\ExecutionOptions;
use Fedor\MessageBus\Message\Command;
use Fedor\MessageBus\Message\Query;
use Fedor\MessageBus\MessageBus;
use Testo\Assert;
use Testo\Test;
use Thesis\Dic;
use Thesis\Dic\Module;
use Thesis\Dic\Ref;
use Throwable;

#[Test]
final class ApplicationMessageBusModuleTest
{
    public function testNestedDispatchReusesScopeAndPropagatesCorrelation(): void
    {
        $trace = new ScenarioTrace();
        $correlationContext = new CorrelationContext();

        Dic::run(
            new ScenarioMessageBusModule($trace, $correlationContext),
            static function (MessageBus $messageBus) use ($trace, $correlationContext): void {
                $correlationContext->run(
                    new CorrelationIds(
                        requestId: 'request-1',
                        correlationId: 'correlation-1',
                        operationId: 'operation-1',
                    ),
                    static function () use ($messageBus): void {
                        $messageBus->dispatch(
                            new ScenarioRootCommand(),
                            new ExecutionOptions(correlationId: 'correlation-1'),
                        );
                    },
                );

                Assert::notNull($trace->rootEnvelope);
                Assert::notNull($trace->nestedEnvelope);
                Assert::notNull($trace->rootCorrelation);
                Assert::notNull($trace->nestedCorrelation);
                Assert::same($trace->nestedResult, 'nested-result');
                Assert::notSame($trace->rootEnvelope->messageId, $trace->nestedEnvelope->messageId);
                Assert::same($trace->nestedEnvelope->correlationId, 'correlation-1');
                Assert::same($trace->nestedEnvelope->causationId, $trace->rootEnvelope->messageId);
                Assert::same($trace->rootCorrelation->requestId, 'request-1');
                Assert::same($trace->rootCorrelation->messageId, $trace->rootEnvelope->messageId);
                Assert::same($trace->nestedCorrelation->requestId, 'request-1');
                Assert::same($trace->nestedCorrelation->messageId, $trace->nestedEnvelope->messageId);
                Assert::same($trace->nestedCorrelation->operationId, 'operation-1');
                Assert::same($trace->rootScopeId, $trace->nestedScopeId);
                Assert::same($trace->scopesCreated, 1);
                Assert::same($trace->scopesDisposed, 1);
                Assert::same($trace->disposedError, null);
                Assert::same($correlationContext->current(), null);
            },
        );
    }

    public function testNestedDispatchInheritsCancellationAndDisposesTheScope(): void
    {
        $trace = new ScenarioTrace(cancelBeforeNested: true);
        $correlationContext = new CorrelationContext();

        Dic::run(
            new ScenarioMessageBusModule($trace, $correlationContext),
            static function (MessageBus $messageBus) use ($trace, $correlationContext): void {
                $error = null;
                try {
                    $messageBus->dispatch(
                        new ScenarioRootCommand(),
                        new ExecutionOptions(cancellation: $trace->cancellation->getCancellation()),
                    );
                } catch (CancelledException $exception) {
                    $error = $exception;
                }

                Assert::instanceOf($error, CancelledException::class);
                Assert::false($trace->nestedInvoked);
                Assert::same($trace->scopesCreated, 1);
                Assert::same($trace->scopesDisposed, 1);
                Assert::instanceOf($trace->disposedError, CancelledException::class);
                Assert::same($correlationContext->current(), null);
            },
        );
    }
}

/** @implements Module<Ref<MessageBus>> */
final readonly class ScenarioMessageBusModule implements Module
{
    public function __construct(
        private ScenarioTrace $trace,
        private CorrelationContext $correlationContext,
    ) {}

    /** @return Ref<MessageBus> */
    public function configure(Dic $dic): Ref
    {
        $trace = $dic->value($this->trace);
        $correlationContext = $dic->value($this->correlationContext);
        $scope = $dic
            ->object(ScenarioScope::class)
            ->arg('trace', $trace)
            ->scoped()
            ->disposer(static function (ScenarioScope $scope, ?Throwable $error): void {
                $scope->dispose($error);
            });
        $rootHandler = $dic
            ->object(ScenarioRootCommandHandler::class)
            ->args([
                'trace' => $trace,
                'correlationContext' => $correlationContext,
                'scope' => $scope,
            ])
            ->canBeScoped();
        $nestedHandler = $dic
            ->object(ScenarioNestedQueryHandler::class)
            ->args([
                'trace' => $trace,
                'correlationContext' => $correlationContext,
                'scope' => $scope,
            ])
            ->canBeScoped();

        return $dic->import(new ApplicationMessageBusModule(
            new MessageBusRegistrySource(
                classes: [ScenarioRootCommandHandler::class, ScenarioNestedQueryHandler::class],
                services: [
                    ScenarioRootCommandHandler::class => ScenarioRootCommandHandler::class,
                    ScenarioNestedQueryHandler::class => ScenarioNestedQueryHandler::class,
                ],
                flows: MessageBusRegistrySource::empty()->flows,
            ),
            $correlationContext,
            [
                ScenarioRootCommandHandler::class => $rootHandler,
                ScenarioNestedQueryHandler::class => $nestedHandler,
            ],
        ));
    }
}

final class ScenarioTrace
{
    public readonly DeferredCancellation $cancellation;
    public ?Envelope $rootEnvelope = null;
    public ?Envelope $nestedEnvelope = null;
    public ?CorrelationIds $rootCorrelation = null;
    public ?CorrelationIds $nestedCorrelation = null;
    public ?string $nestedResult = null;
    public ?int $rootScopeId = null;
    public ?int $nestedScopeId = null;
    public bool $nestedInvoked = false;
    public int $scopesCreated = 0;
    public int $scopesDisposed = 0;
    public ?Throwable $disposedError = null;

    public function __construct(public bool $cancelBeforeNested = false)
    {
        $this->cancellation = new DeferredCancellation();
    }
}

final readonly class ScenarioScope
{
    public int $id;

    public function __construct(private ScenarioTrace $trace)
    {
        $this->id = ++$this->trace->scopesCreated;
    }

    public function dispose(?Throwable $error): void
    {
        ++$this->trace->scopesDisposed;
        $this->trace->disposedError = $error;
    }
}

final readonly class ScenarioRootCommand implements Command {}

/** @implements Query<string> */
final readonly class ScenarioNestedQuery implements Query {}

#[CommandHandler(ScenarioRootCommand::class)]
final readonly class ScenarioRootCommandHandler
{
    public function __construct(
        private ScenarioTrace $trace,
        private CorrelationContext $correlationContext,
        private ScenarioScope $scope,
    ) {}

    public function __invoke(ScenarioRootCommand $command, MessageContextInterface $context): void
    {
        $this->trace->rootEnvelope = $context->envelope();
        $this->trace->rootCorrelation = $this->correlationContext->current();
        $this->trace->rootScopeId = $this->scope->id;

        if ($this->trace->cancelBeforeNested) {
            $this->trace->cancellation->cancel();
        }

        $this->trace->nestedResult = $context->ask(new ScenarioNestedQuery());
    }
}

#[QueryHandler(ScenarioNestedQuery::class)]
final readonly class ScenarioNestedQueryHandler
{
    public function __construct(
        private ScenarioTrace $trace,
        private CorrelationContext $correlationContext,
        private ScenarioScope $scope,
    ) {}

    public function __invoke(ScenarioNestedQuery $query, MessageContextInterface $context): string
    {
        $this->trace->nestedInvoked = true;
        $this->trace->nestedEnvelope = $context->envelope();
        $this->trace->nestedCorrelation = $this->correlationContext->current();
        $this->trace->nestedScopeId = $this->scope->id;

        return 'nested-result';
    }
}
