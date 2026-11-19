<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use PgSql\Connection;

/** Collects opt-in worker timings and flushes once per completed file. */
final class GarFusedRangeProfileRecorder
{
    /** @var list<string> */
    private array $rows = [];

    private function __construct(
        private readonly Connection $connection,
        private readonly int $operationId,
        private readonly GarXmlSourceFile $file,
    ) {}

    public static function fromEnvironment(
        Connection $connection,
        int $operationId,
        GarXmlSourceFile $file,
    ): ?self {
        return \getenv('GAR_FUSED_RANGE_PROFILE') === '1'
            ? new self($connection, $operationId, $file)
            : null;
    }

    public function record(GarDirectCopyBatchResult $result): void
    {
        $profile = $result->profile;
        if (!$profile instanceof GarFusedRangeProfile) {
            throw new GarImportFailed('GAR fused range profile result is missing while profiling is enabled.');
        }

        $this->rows[] = new GarCopyRowEncoder()->encode([
            (string) $this->operationId,
            $this->file->key,
            $this->file->relativePath,
            (string) $result->firstRow,
            (string) $result->lastRow,
            (string) $result->encodedBytes,
            (string) $result->backendPid,
            (string) $profile->prefetchNanoseconds,
            (string) $profile->permitWaitNanoseconds,
            (string) $profile->connectionNanoseconds,
            (string) $profile->permitDecodeNanoseconds,
            (string) $profile->beginNanoseconds,
            (string) $profile->copyBeginNanoseconds,
            (string) $profile->copySendNanoseconds,
            (string) $profile->copyEndNanoseconds,
            (string) $profile->ledgerNanoseconds,
            (string) $profile->commitNanoseconds,
            (string) $profile->writerNanoseconds,
            (string) $profile->taskNanoseconds,
        ]);
    }

    public function flush(): void
    {
        if ($this->rows === []) {
            return;
        }
        if (!\pg_copy_from($this->connection, 'gar_fused_range_profile', $this->rows)) {
            throw new GarImportFailed('Unable to flush GAR fused range profile.');
        }
        $this->rows = [];
    }
}
