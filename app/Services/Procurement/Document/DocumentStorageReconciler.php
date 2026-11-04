<?php

declare(strict_types=1);

namespace App\Services\Procurement\Document;

use App\Dto\Procurement\Document\BlobReference;
use App\Dto\Procurement\Document\StorageReservationRequest;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Document\StoragePhase;
use App\Repositories\Procurement\Document\ArtifactRepository;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Procurement\Process\Contracts\ReconcileCategory;
use App\Services\Procurement\Process\ProcessLog;

/** Object I/O stays outside DB locks. A dead lease alone never releases uncertain tool bytes. */
final class DocumentStorageReconciler implements ReconcileCategory
{
    private ?string $cursor = null;

    private ?string $blobVerifiedAtCursor = null;

    private ?ProcessUuid $blobIdCursor = null;

    public function __construct(
        private readonly PrepareWorkspace $workspace,
        private readonly ArtifactGarbageCollector $gc,
        private readonly ArtifactRepository $artifacts,
        private readonly ?BlobStore $blobs = null,
    ) {}

    public function name(): string
    {
        return 'document_storage_cleanup';
    }

    public function reconcile(): int
    {
        $rows = $this->reservationCandidates($this->cursor);
        if ($rows === [] && $this->cursor !== null) {
            $this->cursor = null;
            $rows = $this->reservationCandidates(null);
        }
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
            try {
                $cleaned += (int) ($row->string('status') === 'writing'
                    ? $this->workspace->cleanupOrStoppedFile($r)
                    : $this->gc->cleanup($r));
            } catch (\LogicException $invariant) {
                throw $invariant;
            } catch (\Throwable $failure) {
                ProcessLog::daemonError('reconcile', 'document_storage_reservation_cleanup_failed', $failure, [
                    'reservation_id' => $r->id->value,
                    'disk' => $r->disk,
                ]);
            }
        }
        if (count($rows) < 50) {
            $this->cursor = null;
        }

        $repairs = LedgerRow::all(
            "SELECT b.*, encode(b.content_hash, 'hex') AS hash
             FROM prc_document_blobs b
             JOIN prc_storage_reservations s ON s.id = b.id
             WHERE s.status = 'writing' AND b.deletion_marked_at IS NULL
             ORDER BY b.id LIMIT 25",
        );
        foreach ($repairs as $repairRow) {
            $blob = new BlobReference(
                new ProcessUuid($repairRow->string('id')),
                new ProcessUuid($repairRow->string('storage_domain_id')),
                new ContentHash($repairRow->string('hash')),
                $repairRow->string('disk'),
                $repairRow->string('object_key'),
                $repairRow->int('size_bytes'),
            );
            try {
                $cleaned += (int) ($this->blobs ?? app(BlobStore::class))->recoverStoppedRepair($blob);
            } catch (\LogicException $invariant) {
                throw $invariant;
            } catch (\Throwable $failure) {
                ProcessLog::daemonError('reconcile', 'document_storage_repair_recovery_failed', $failure, [
                    'blob_id' => $blob->id->value,
                    'disk' => $blob->disk,
                ]);
            }
        }

        $candidates = $this->artifacts->unreferencedBatch(900, 25, $this->blobVerifiedAtCursor, $this->blobIdCursor);
        if ($candidates === [] && $this->blobIdCursor !== null) {
            $this->blobVerifiedAtCursor = null;
            $this->blobIdCursor = null;
            $candidates = $this->artifacts->unreferencedBatch(900, 25);
        }
        foreach ($candidates as $candidate) {
            $blob = $candidate['blob'];
            $this->blobVerifiedAtCursor = $candidate['verifiedAt'];
            $this->blobIdCursor = $blob->id;
            try {
                $mark = $this->artifacts->markDeletion($blob, 900);
                if ($mark !== null && $this->gc->deleteMarked($mark)) {
                    $cleaned++;
                }
            } catch (\LogicException $invariant) {
                throw $invariant;
            } catch (\Throwable $failure) {
                ProcessLog::daemonError('reconcile', 'document_storage_blob_cleanup_failed', $failure, [
                    'blob_id' => $blob->id->value,
                    'disk' => $blob->disk,
                ]);
            }
        }
        if (count($candidates) < 25) {
            $this->blobVerifiedAtCursor = null;
            $this->blobIdCursor = null;
        }

        return $cleaned;
    }

    /**
     * @return list<LedgerRow>
     */
    private function reservationCandidates(?string $cursor): array
    {
        return LedgerRow::all(
            "SELECT s.* FROM prc_storage_reservations s
             LEFT JOIN prc_process_work w ON w.id = s.work_id
             LEFT JOIN prc_outbound_operations o ON o.id = s.operation_id
             WHERE s.status IN ('reserved','writing','staged','cleaning')
               AND NOT EXISTS (SELECT 1 FROM prc_document_blobs b WHERE b.disk=s.disk AND b.object_key=s.object_key)
               AND (
                   (s.work_id IS NOT NULL AND (w.state<>'running' OR w.generation<>s.generation OR w.owner_token IS DISTINCT FROM s.owner_token))
                   OR (s.work_id IS NULL AND s.operation_id IS NOT NULL AND o.state<>'open')
               )
               AND (s.operation_id IS NULL OR o.state<>'open')
               AND (?::uuid IS NULL OR s.id>?::uuid)
             ORDER BY s.id LIMIT 50",
            [$cursor, $cursor],
        );
    }

    private static function uuid(?string $value): ?ProcessUuid
    {
        return $value === null ? null : new ProcessUuid($value);
    }
}
