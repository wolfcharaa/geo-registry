<?php

declare(strict_types=1);

namespace App\Application\Observability;

use InvalidArgumentException;

final readonly class CorrelationIds
{
    public function __construct(
        public ?string $requestId = null,
        public ?string $messageId = null,
        public ?string $correlationId = null,
        public ?string $operationId = null,
        public ?string $jobId = null,
    ) {
        foreach ($this->toArray() as $name => $value) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $value) !== 1) {
                throw new InvalidArgumentException(sprintf('%s has an invalid correlation identifier.', $name));
            }
        }
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return array_filter([
            'request_id' => $this->requestId,
            'message_id' => $this->messageId,
            'correlation_id' => $this->correlationId,
            'operation_id' => $this->operationId,
            'job_id' => $this->jobId,
        ], static fn(?string $value): bool => $value !== null);
    }
}
