<?php

declare(strict_types=1);

namespace App\Application\MessageBus;

use Fedor\MessageBus\Codec\MessageCodecRegistry;
use Fedor\MessageBus\Codec\MessageCodecRegistryValidationRule;
use Fedor\MessageBus\Registry\CompiledMessageRegistry;
use Fedor\MessageBus\Registry\MessageRegistryCompiler;

final readonly class ApplicationRegistryCompiler
{
    private MessageRegistryCompiler $compiler;

    public function __construct(
        ?MessageCodecRegistry $codecs = null,
        ?MessageRegistryCompiler $compiler = null,
    ) {
        $this->compiler = $compiler ?? new MessageRegistryCompiler(
            validationRules: $codecs === null ? [] : [new MessageCodecRegistryValidationRule($codecs)],
        );
    }

    public function compile(MessageBusRegistrySource $source): CompiledMessageRegistry
    {
        return new CompiledMessageRegistry($this->compiler->compile(
            classes: $source->classes,
            flows: $source->flows,
            sourceHash: $source->sourceHash(),
        ));
    }
}
