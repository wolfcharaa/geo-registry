<?php

declare(strict_types=1);

namespace App\Application\MessageBus;

use Fedor\MessageBus\Registry\CompiledMessageRegistry;
use Fedor\MessageBus\Registry\MessageRegistryCompiler;

final readonly class ApplicationRegistryCompiler
{
    public function __construct(private MessageRegistryCompiler $compiler = new MessageRegistryCompiler()) {}

    public function compile(MessageBusRegistrySource $source): CompiledMessageRegistry
    {
        return new CompiledMessageRegistry($this->compiler->compile(
            classes: $source->classes,
            flows: $source->flows,
            sourceHash: $source->sourceHash(),
        ));
    }
}
