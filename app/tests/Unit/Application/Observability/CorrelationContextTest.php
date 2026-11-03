<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Observability;

use function Amp\async;
use function Amp\delay;

use App\Application\Observability\CorrelationContext;
use App\Application\Observability\CorrelationIds;
use App\Infrastructure\Logging\CorrelationProcessor;
use DateTimeImmutable;
use LogicException;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use Testo\Assert;
use Testo\Test;

#[Test]
final class CorrelationContextTest
{
    public function testItIsolatesParallelFiberContexts(): void
    {
        $context = new CorrelationContext();
        $first = async(fn(): string => $context->run(
            new CorrelationIds(requestId: 'request-one', correlationId: 'correlation-one'),
            static function () use ($context): string {
                delay(0.002);

                return $context->current()->requestId
                    ?? throw new LogicException('First correlation context is missing.');
            },
        ));
        $second = async(fn(): string => $context->run(
            new CorrelationIds(requestId: 'request-two', correlationId: 'correlation-two'),
            static function () use ($context): string {
                delay(0.001);

                return $context->current()->requestId
                    ?? throw new LogicException('Second correlation context is missing.');
            },
        ));

        Assert::same($first->await(), 'request-one');
        Assert::same($second->await(), 'request-two');
        Assert::same($context->current(), null);
    }

    public function testProcessorAddsStableJsonFieldsFromCurrentContext(): void
    {
        $context = new CorrelationContext();
        $processor = new CorrelationProcessor($context, 'geo-registry', 'http');
        $record = new LogRecord(
            new DateTimeImmutable('2026-11-03T12:00:00+00:00'),
            'geo-registry',
            Level::Info,
            'Request completed',
            ['event' => 'http.request.completed'],
        );

        $processed = $context->run(
            new CorrelationIds(requestId: 'request-a', correlationId: 'correlation-a'),
            static fn(): LogRecord => $processor($record),
        );
        $json = (new JsonFormatter(ignoreEmptyContextAndExtra: true))->format($processed);
        json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        Assert::same($processed->extra, [
            'event' => 'http.request.completed',
            'service' => 'geo-registry',
            'runtime' => 'http',
            'request_id' => 'request-a',
            'correlation_id' => 'correlation-a',
        ]);
        Assert::true(str_ends_with($json, "\n"));
    }
}
