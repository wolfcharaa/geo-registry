<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use App\Application\Observability\CorrelationContext;
use Monolog\LogRecord;

final readonly class CorrelationProcessor
{
    public function __construct(
        private CorrelationContext $context,
        private string $service,
        private string $runtime,
    ) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $correlation = $this->context->current()?->toArray() ?? [];
        $event = $record->context['event'] ?? 'log';

        return $record->with(extra: array_merge(
            $record->extra,
            [
                'event' => is_string($event) ? $event : 'log',
                'service' => $this->service,
                'runtime' => $this->runtime,
            ],
            $correlation,
        ));
    }
}
