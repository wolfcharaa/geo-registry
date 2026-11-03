<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Health;

use Amp\Cancellation;

use function Amp\delay;

use App\Application\Health\HealthCheckDefinition;
use App\Application\Health\HealthCheckInterface;
use App\Application\Health\HealthCheckPolicy;
use App\Application\Health\HealthRegistry;
use App\Application\Health\HealthStatus;
use App\Application\Health\StartupState;
use App\Application\Observability\CorrelationContext;
use App\Application\Observability\CorrelationIds;
use Closure;
use Psr\Log\NullLogger;
use RuntimeException;
use Testo\Assert;
use Testo\Test;

#[Test]
final class HealthRegistryTest
{
    public function testRequiredFailureMakesReadinessUnavailable(): void
    {
        $registry = self::registry([
            self::check('postgres', HealthCheckPolicy::Required, static function (Cancellation $_cancellation): void {
                throw new RuntimeException('connection details must not leak');
            }),
            self::check(
                'opensearch',
                HealthCheckPolicy::Optional,
                static function (Cancellation $_cancellation): void {},
            ),
        ]);

        $report = $registry->readiness();

        Assert::same($report->status, HealthStatus::Unavailable);
        Assert::same($report->toArray(), [
            'status' => 'unavailable',
            'components' => [
                ['name' => 'postgres', 'policy' => 'required', 'status' => 'unavailable'],
                ['name' => 'opensearch', 'policy' => 'optional', 'status' => 'ok'],
            ],
        ]);
    }

    public function testOptionalFailureMakesReadinessDegraded(): void
    {
        $registry = self::registry([
            self::check(
                'postgres',
                HealthCheckPolicy::Required,
                static function (Cancellation $_cancellation): void {},
            ),
            self::check('opensearch', HealthCheckPolicy::Optional, static function (Cancellation $_cancellation): void {
                throw new RuntimeException('unavailable');
            }),
        ]);

        Assert::same($registry->readiness()->status, HealthStatus::Degraded);
    }

    public function testLivenessIsLocalAndStartupIsExplicit(): void
    {
        $calls = 0;
        $startupState = new StartupState();
        $registry = self::registry([
            self::check('postgres', HealthCheckPolicy::Required, static function (
                Cancellation $_cancellation,
            ) use (&$calls): void {
                ++$calls;
            }),
        ], $startupState);

        Assert::same($registry->liveness()->status, HealthStatus::Ok);
        Assert::same($calls, 0);
        Assert::same($registry->startup()->status, HealthStatus::Unavailable);

        $startupState->markComplete();

        Assert::same($registry->startup()->status, HealthStatus::Ok);
    }

    public function testChecksRunInParallelAndHonorTheirDeadlines(): void
    {
        $barrier = new HealthCheckBarrier(2);
        $registry = self::registry([
            self::check('one', HealthCheckPolicy::Required, $barrier->await(...), 0.1),
            self::check('two', HealthCheckPolicy::Required, $barrier->await(...), 0.1),
            self::check(
                'slow-optional',
                HealthCheckPolicy::Optional,
                static fn(Cancellation $cancellation) => delay(1, cancellation: $cancellation),
                0.001,
            ),
        ]);

        $report = $registry->readiness();

        Assert::same($barrier->arrivals, 2);
        Assert::same($report->status, HealthStatus::Degraded);
    }

    public function testReadinessPropagatesCorrelationIntoCheckFibers(): void
    {
        $context = new CorrelationContext();
        $observedRequestId = null;
        $registry = self::registry([
            self::check('postgres', HealthCheckPolicy::Required, static function (
                Cancellation $_cancellation,
            ) use ($context, &$observedRequestId): void {
                $observedRequestId = $context->current()?->requestId;
            }),
        ], correlationContext: $context);

        $context->run(
            new CorrelationIds(requestId: 'request-health', correlationId: 'correlation-health'),
            static fn() => $registry->readiness(),
        );

        Assert::same($observedRequestId, 'request-health');
        Assert::same($context->current(), null);
    }

    /**
     * @param list<HealthCheckDefinition> $checks
     */
    private static function registry(
        array $checks,
        ?StartupState $startupState = null,
        ?CorrelationContext $correlationContext = null,
    ): HealthRegistry {
        return new HealthRegistry(
            $checks,
            $startupState ?? new StartupState(),
            new NullLogger(),
            $correlationContext ?? new CorrelationContext(),
        );
    }

    /** @param Closure(Cancellation): void $callback */
    private static function check(
        string $name,
        HealthCheckPolicy $policy,
        Closure $callback,
        float $deadlineSeconds = 1,
    ): HealthCheckDefinition {
        return new HealthCheckDefinition(
            $name,
            $policy,
            $deadlineSeconds,
            new CallbackHealthCheck($callback),
        );
    }
}

final readonly class CallbackHealthCheck implements HealthCheckInterface
{
    /** @param Closure(Cancellation): void $callback */
    public function __construct(private Closure $callback) {}

    public function check(Cancellation $cancellation): void
    {
        ($this->callback)($cancellation);
    }
}

final class HealthCheckBarrier
{
    public int $arrivals = 0;

    public function __construct(private readonly int $expected) {}

    public function await(Cancellation $cancellation): void
    {
        ++$this->arrivals;
        while ($this->arrivals < $this->expected) {
            delay(0.001, cancellation: $cancellation);
        }
    }
}
