<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Authority;

use App\Dto\Procurement\Process\Primitives\ProcessUuid;

/** Preloaded immutable analysis ancestry; the resolver performs no I/O. */
final readonly class AnalysisSubjects
{
    public function __construct(
        public int $documentId,
        public int $versionId,
        public ProcessUuid $runId,
        public ?ProcessUuid $windowId,
    ) {}
}
