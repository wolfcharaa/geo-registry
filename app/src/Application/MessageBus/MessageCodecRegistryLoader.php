<?php

declare(strict_types=1);

namespace App\Application\MessageBus;

use Fedor\MessageBus\Codec\MessageCodecRegistry;
use RuntimeException;

final class MessageCodecRegistryLoader
{
    public static function fromFile(string $file): MessageCodecRegistry
    {
        if (!is_file($file)) {
            throw new RuntimeException(sprintf('Message codec registry was not found: %s', $file));
        }

        $registry = require $file;

        return $registry instanceof MessageCodecRegistry
            ? $registry
            : throw new RuntimeException(sprintf(
                'Message codec registry source must return %s.',
                MessageCodecRegistry::class,
            ));
    }

    private function __construct() {}
}
