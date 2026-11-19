<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\ImportGarRaw;

use RuntimeException;
use Throwable;

final class ImportGarRawFailed extends RuntimeException
{
    /** @param list<ImportGarRawDiagnostic> $diagnostics */
    public function __construct(
        string $message = '',
        public readonly array $diagnostics = [],
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
