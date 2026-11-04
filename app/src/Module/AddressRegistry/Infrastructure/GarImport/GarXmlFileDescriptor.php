<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use InvalidArgumentException;

final readonly class GarXmlFileDescriptor
{
    private string $identityAttribute;

    /**
     * @param array<string, string> $attributes Stage column => XML attribute.
     * @param list<string>|null $targetColumns
     * @param list<string>|null $selectExpressions
     */
    public function __construct(
        public string $key,
        public string $filenamePattern,
        public string $table,
        public int $priority,
        public array $attributes,
        private ?array $targetColumns = null,
        private ?array $selectExpressions = null,
    ) {
        if ($this->attributes === []) {
            throw new InvalidArgumentException('GAR XML descriptor must contain at least one column.');
        }
        self::assertIdentifier($this->table);
        foreach ($this->attributes as $column => $_attribute) {
            if (!is_string($column)) {
                throw new InvalidArgumentException('GAR XML stage column must be a string.');
            }
            self::assertIdentifier($column);
        }
        $attributes = $this->attributes;
        $identityAttribute = reset($attributes);
        $this->identityAttribute = $identityAttribute;

        if (($this->targetColumns === null) !== ($this->selectExpressions === null)) {
            throw new InvalidArgumentException('Custom GAR merge columns and expressions must be configured together.');
        }
        if ($this->targetColumns !== null) {
            if ($this->selectExpressions === null || count($this->targetColumns) !== count($this->selectExpressions)) {
                throw new InvalidArgumentException('Custom GAR merge columns and expressions must have equal length.');
            }
        }
        foreach ($this->targetColumns ?? [] as $column) {
            self::assertIdentifier($column);
        }
    }

    public function matches(string $filename): bool
    {
        return preg_match($this->filenamePattern, $filename) === 1;
    }

    /** @return list<string> */
    public function stageColumns(): array
    {
        $columns = [];
        foreach ($this->attributes as $column => $_attribute) {
            $columns[] = $column;
        }

        return $columns;
    }

    public function identityAttribute(): string
    {
        return $this->identityAttribute;
    }

    public function mergeSql(string $stageTable): string
    {
        self::assertIdentifier($stageTable);

        $targetColumns = $this->targetColumns ?? $this->stageColumns();
        $selectExpressions = $this->selectExpressions
            ?? array_map(static fn(string $column): string => "s.{$column}", $targetColumns);
        $updates = [];
        foreach ($targetColumns as $column) {
            if ($column !== 'id') {
                $updates[] = "{$column} = EXCLUDED.{$column}";
            }
        }

        $conflict = $updates === []
            ? 'DO NOTHING'
            : 'DO UPDATE SET ' . implode(', ', $updates);

        return sprintf(
            'INSERT INTO %s (%s) SELECT %s FROM %s s ON CONFLICT (id) %s',
            $this->table,
            implode(', ', $targetColumns),
            implode(', ', $selectExpressions),
            $stageTable,
            $conflict,
        );
    }

    private static function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $identifier) !== 1) {
            throw new InvalidArgumentException("Unsafe SQL identifier: {$identifier}");
        }
    }
}
