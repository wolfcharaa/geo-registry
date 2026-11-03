<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\MessageBus;

use App\Application\MessageBus\ApplicationRegistryCompiler;
use App\Application\MessageBus\MessageBusRegistrySource;
use InvalidArgumentException;
use stdClass;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Test;

#[Test]
final class MessageBusRegistrySourceTest
{
    public function testItCompilesTheExplicitEmptyApplicationRegistry(): void
    {
        $source = MessageBusRegistrySource::empty();
        $registry = (new ApplicationRegistryCompiler())->compile($source);

        Assert::same($registry->compiled()->bindings(), []);
        Assert::same($registry->definition()->sourceHash, $source->sourceHash());
    }

    public function testSourceHashIsIndependentFromDeclarationOrder(): void
    {
        $first = new MessageBusRegistrySource(
            [self::class, stdClass::class],
            ['second' => stdClass::class, 'first' => self::class],
        );
        $second = new MessageBusRegistrySource(
            [stdClass::class, self::class],
            ['first' => self::class, 'second' => stdClass::class],
        );

        Assert::same($first->sourceHash(), $second->sourceHash());
    }

    #[ExpectException(InvalidArgumentException::class)]
    public function testItRejectsDuplicateRegistryClasses(): void
    {
        new MessageBusRegistrySource([stdClass::class, stdClass::class], []);
    }
}
