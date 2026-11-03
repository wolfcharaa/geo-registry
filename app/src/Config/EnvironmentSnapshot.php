<?php

declare(strict_types=1);

namespace App\Config;

use InvalidArgumentException;
use LogicException;

final readonly class EnvironmentSnapshot
{
    /** @var array<string, string> */
    private array $values;

    /** @param array<string, string> $values */
    public function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function capture(): self
    {
        return new self(getenv());
    }

    public function string(string $name, string $default): string
    {
        return $this->optionalString($name) ?? $default;
    }

    public function requiredString(string $name): string
    {
        return $this->optionalString($name)
            ?? throw new InvalidArgumentException(sprintf('%s must be a non-empty string.', $name));
    }

    public function optionalString(string $name): ?string
    {
        $value = $this->values[$name] ?? null;
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    public function positiveInteger(string $name, int $default): int
    {
        $value = $this->optionalString($name);
        if ($value === null) {
            return $default;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($integer === false) {
            throw new InvalidArgumentException(sprintf('%s must be a positive integer.', $name));
        }

        return $integer;
    }

    public function positiveFloat(string $name, float $default): float
    {
        $value = $this->optionalString($name);
        if ($value === null) {
            return $default;
        }

        $float = filter_var($value, FILTER_VALIDATE_FLOAT);
        if ($float === false || $float <= 0) {
            throw new InvalidArgumentException(sprintf('%s must be greater than zero.', $name));
        }

        return $float;
    }

    /** @return array<string, string> */
    public function redactedValues(): array
    {
        $redacted = [];
        foreach ($this->values as $name => $value) {
            if (!is_string($name)) {
                throw new LogicException('Environment variable names must be strings.');
            }
            $redacted[$name] = preg_match('/(?:CREDENTIAL|DSN|KEY|PASSWORD|SECRET|TOKEN)/i', $name) === 1
                ? '[redacted]'
                : $value;
        }

        return $redacted;
    }
}
