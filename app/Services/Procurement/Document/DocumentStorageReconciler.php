<?php

declare(strict_types=1);

namespace App\Services\Procurement\Document;

use App\Dto\Procurement\Document\StorageReservationRequest;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Document\StoragePhase;
use App\Repositories\Procurement\Document\ArtifactRepository;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Procurement\Process\Contracts\ReconcileCategory;

/** Object I/O stays outside DB locks. A dead lease alone never releases uncertain tool bytes. */
final class DocumentStorageReconciler implements ReconcileCategory
{
    private ?string $cursor = null;

    public function __construct(
        private readonly PrepareWorkspace $workspace,
        private readonly ArtifactGarbageCollector $gc,
        private readonly ArtifactRepository $artifacts,
    ) {}

    public function name(): string
    {
        return 'document_storage_cleanup';
    }

    public function reconcile(): int
    {
        $rows = LedgerRow::all("SELECT s.* FROM prc_storage_reservations s JOIN prc_process_work w ON w.id=s.work_id
            WHERE s.status IN ('reserved','writing','staged','cleaning')
            AND NOT EXISTS (SELECT 1 FROM prc_document_blobs b WHERE b.disk=s.disk AND b.object_key=s.object_key)
            AND (w.state<>'running' OR w.generation<>s.generation OR w.owner_token IS DISTINCT FROM s.owner_token)
            AND (?::uuid IS NULL OR s.id>?::uuid) ORDER BY s.id LIMIT 50", [$this->cursor, $this->cursor]);
        if ($rows === []) {
            $this->cursor = null;
        }
        $cleaned = 0;
        foreach ($rows as $row) {
            $this->cursor = $row->string('id');
            $r = new StorageReservationRequest(new ProcessUuid($row->string('id')), new ProcessUuid($row->string('domain_id')),
                new ProcessUuid($row->string('owner_token')), $row->int('generation'), $row->int('byte_upper_bound'),
                StoragePhase::from($row->string('phase')), $row->string('disk'), self::uuid($row->nullableString('work_id')),
                self::uuid($row->nullableString('attempt_id')), self::uuid($row->nullableString('operation_id')));
            $cleaned += (int) ($r->phase === StoragePhase::Prepare && $row->string('status') === 'writing'
                ? $this->workspace->cleanup($r) : $this->gc->cleanup($r));
        }
        foreach ($this->artifacts->unreferencedCandidates(900, 25) as $blob) {
            $mark = $this->artifacts->markDeletion($blob, 900);
            if ($mark !== null && $this->gc->deleteMarked($mark)) {
                $cleaned++;
            }
        }

        return $cleaned;
    }

    private static function uuid(?string $value): ?ProcessUuid
    {
        return $value === null ? null : new ProcessUuid($value);
    }
}
