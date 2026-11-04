<?php

declare(strict_types=1);

namespace App\Application\MessageBus;

use Fedor\MessageBus\Flow\FlowDefinition;
use Fedor\MessageBus\Flow\FlowRegistry;
use InvalidArgumentException;
use RuntimeException;

final readonly class MessageBusRegistrySource
{
    /** @var list<class-string> */
    public array $classes;

    /** @var array<string, class-string<object>> */
    public array $services;

    /**
     * @param list<class-string> $classes
     * @param array<string, class-string<object>> $services
     */
    public function __construct(
        array $classes,
        array $services,
        public FlowRegistry $flows = new FlowRegistry(),
    ) {
        if (count($classes) !== count(array_unique($classes))) {
            throw new InvalidArgumentException('MessageBus registry classes must not contain duplicates.');
        }

        sort($classes, SORT_STRING);
        ksort($services, SORT_STRING);
        $this->classes = $classes;
        $this->services = $services;
    }

    public static function empty(): self
    {
        return new self(
            [],
            [],
            new FlowRegistry(
                FlowDefinition::sync('default')->middleware(
                    CorrelationContextInterceptor::class,
                    MessageBusTelemetryInterceptor::class,
                ),
            ),
        );
    }

    public static function fromFile(string $file): self
    {
        if (!is_file($file)) {
            throw new RuntimeException(sprintf('MessageBus registry source was not found: %s', $file));
        }

        $source = require $file;

        return $source instanceof self
            ? $source
            : throw new RuntimeException(sprintf('MessageBus registry source must return %s.', self::class));
    }

    public function sourceHash(): string
    {
        return hash('sha256', json_encode([
            'classes' => $this->classes,
            'flows' => $this->flows->toArray(),
            'services' => $this->services,
        ], JSON_THROW_ON_ERROR));
    }
}
