<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

/** Immutable metadata of a receipt verified by the owning provider resolver. No object I/O here. */
final readonly class ReceiptRef
{
    public function __construct(
        public ProcessUuid $id,
        public string $disk,
        public string $key,
        public ContentHash $sha256,
        public int $schemaVersion,
    ) {
        ProcessValue::text($disk);
        ProcessValue::text($key);
        ProcessValue::positive($schemaVersion);
    }
}
