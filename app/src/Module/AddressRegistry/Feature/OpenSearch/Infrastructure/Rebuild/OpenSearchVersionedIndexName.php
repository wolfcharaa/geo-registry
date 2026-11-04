<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\OpenSearch\Infrastructure\Rebuild;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class OpenSearchVersionedIndexName
{
    public static function generate(string $alias, ?DateTimeImmutable $now = null): string
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $alias) !== 1) {
            throw new InvalidArgumentException('OpenSearch alias has an invalid name.');
        }

        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $index = sprintf('%s-v%s', $alias, $now->format('YmdHisv'));
        if (strlen($index) > 255) {
            throw new InvalidArgumentException('Generated OpenSearch index name exceeds 255 bytes.');
        }

        return $index;
    }

    private function __construct() {}
}
