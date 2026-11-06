<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\MessageBus;

use App\Application\MessageBus\ApplicationRegistryCompiler;
use App\Application\MessageBus\MessageBusRegistrySource;
use Fedor\MessageBus\Attribute\CommandHandler;
use Fedor\MessageBus\Attribute\MessageAlias;
use Fedor\MessageBus\Codec\MessageCodecRegistry;
use Fedor\MessageBus\Flow\FlowDefinition;
use Fedor\MessageBus\Flow\FlowRegistry;
use Fedor\MessageBus\Message\Command;
use Fedor\MessageBus\Registry\RegistryCompilationException;
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

    public function testItRemovesAnOptionalServiceAndItsUnusedImplementation(): void
    {
        $source = new MessageBusRegistrySource(
            [self::class, stdClass::class],
            ['optional' => self::class, 'required' => stdClass::class],
        );

        $filtered = $source->withoutService('optional');

        Assert::same($filtered->classes, [stdClass::class]);
        Assert::same($filtered->services, ['required' => stdClass::class]);
    }

    #[ExpectException(InvalidArgumentException::class)]
    public function testItRejectsDuplicateRegistryClasses(): void
    {
        new MessageBusRegistrySource([stdClass::class, stdClass::class], []);
    }

    #[ExpectException(RegistryCompilationException::class)]
    public function testItRejectsAnAsyncBindingWithoutAnExplicitCodec(): void
    {
        $source = new MessageBusRegistrySource(
            [MissingCodecHandler::class],
            [MissingCodecHandler::class => MissingCodecHandler::class],
            new FlowRegistry(FlowDefinition::async('async')->transport('postgres', 'runtime')),
        );

        (new ApplicationRegistryCompiler(new MessageCodecRegistry([])))->compile($source);
    }
}

#[MessageAlias('geo-registry.test.missing-codec.v1')]
final readonly class MissingCodecCommand implements Command {}

#[CommandHandler(
    message: MissingCodecCommand::class,
    flow: 'async',
    bindingId: 'geo-registry.test.missing-codec.v1',
    contextAware: false,
)]
final class MissingCodecHandler
{
    public function __invoke(MissingCodecCommand $command): void {}
}
