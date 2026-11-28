<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Details;

use App\Enums\Procurement\DetailSnapshotStatus;

final readonly class DocumentsSnapshot
{
    /** @param list<DocumentData> $documents */
    public function __construct(
        public DetailSnapshotStatus $status,
        public array $documents,
    ) {}

    public function authorizesReconciliation(): bool
    {
        return $this->status === DetailSnapshotStatus::Complete;
    }
}
