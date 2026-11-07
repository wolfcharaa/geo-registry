<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\Activity;

final readonly class GarActivitySnapshot
{
    /**
     * @param list<PostgresStatementActivity> $statements
     * @param list<TargetOperationActivity> $operations
     * @param list<WorkerInstanceActivity> $workers
     * @param list<WorkerQueueActivity> $queues
     */
    public function __construct(
        public array $statements,
        public array $operations,
        public array $workers,
        public array $queues,
    ) {}
}
