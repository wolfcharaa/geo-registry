<?php

declare(strict_types=1);

namespace App\Application\Health;

use function Amp\async;

use Amp\Future;
use Amp\TimeoutCancellation;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class HealthRegistry
{
    /** @param list<HealthCheckDefinition> $checks */
    public function __construct(
        private array $checks,
        private StartupState $startupState,
        private LoggerInterface $logger,
    ) {}

    public function liveness(): HealthReport
    {
        return new HealthReport(HealthStatus::Ok);
    }

    public function startup(): HealthReport
    {
        return new HealthReport(
            $this->startupState->isComplete() ? HealthStatus::Ok : HealthStatus::Unavailable,
        );
    }

    public function readiness(): HealthReport
    {
        $futures = array_map(
            $this->start(...),
            $this->checks,
        );

        $components = [];
        foreach ($futures as $future) {
            $components[] = $future->await();
        }

        return new HealthReport(self::aggregate($components), $components);
    }

    /** @return Future<HealthComponentResult> */
    private function start(HealthCheckDefinition $definition): Future
    {
        return async(fn(): HealthComponentResult => $this->run($definition));
    }

    private function run(HealthCheckDefinition $definition): HealthComponentResult
    {
        try {
            $definition->check->check(new TimeoutCancellation(
                $definition->deadlineSeconds,
                sprintf('Health check %s timed out.', $definition->name),
            ));

            return new HealthComponentResult($definition->name, $definition->policy, true);
        } catch (Throwable $exception) {
            $this->logger->warning('Health check failed', [
                'component' => $definition->name,
                'exception' => $exception,
                'policy' => $definition->policy->value,
            ]);

            return new HealthComponentResult($definition->name, $definition->policy, false);
        }
    }

    /** @param list<HealthComponentResult> $components */
    private static function aggregate(array $components): HealthStatus
    {
        $degraded = false;
        foreach ($components as $component) {
            if ($component->available) {
                continue;
            }
            if ($component->policy === HealthCheckPolicy::Required) {
                return HealthStatus::Unavailable;
            }

            $degraded = true;
        }

        return $degraded ? HealthStatus::Degraded : HealthStatus::Ok;
    }
}
