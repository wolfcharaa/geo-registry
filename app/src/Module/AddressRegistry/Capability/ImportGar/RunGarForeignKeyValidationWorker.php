<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\ImportGar;

use InvalidArgumentException;

final readonly class RunGarForeignKeyValidationWorker
{
    public function __construct(
        public int $operationId,
        public string $tableName,
        public string $constraintName,
    ) {
        if ($this->operationId < 1) {
            throw new InvalidArgumentException('GAR foreign-key worker operation id must be positive.');
        }
        foreach ([$this->tableName, $this->constraintName] as $identifier) {
            if (\preg_match('/^[a-zA-Z_][a-zA-Z0-9_$]*$/D', $identifier) !== 1) {
                throw new InvalidArgumentException('GAR foreign-key worker received an invalid identifier.');
            }
        }
    }
}
