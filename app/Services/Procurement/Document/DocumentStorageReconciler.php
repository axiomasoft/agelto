<?php

declare(strict_types=1);

namespace App\Services\Procurement\Document;

use App\Dto\Procurement\Process\ProcessUuid;
use App\Repositories\Procurement\Document\ArtifactRepository;
use App\Repositories\Procurement\Document\StorageCapacityRepository;
use App\Services\Procurement\Process\Contracts\ReconcileCategory;
use App\Services\Procurement\Process\ProcessLog;

/** Object I/O stays outside DB locks. A dead lease alone never releases uncertain tool bytes. */
final class DocumentStorageReconciler implements ReconcileCategory
{
    private ?ProcessUuid $cursor = null;

    private ?ProcessUuid $repairCursor = null;

    private ?string $blobVerifiedAtCursor = null;

    private ?ProcessUuid $blobIdCursor = null;

    public function __construct(
        private readonly PrepareWorkspace $workspace,
        private readonly ArtifactGarbageCollector $gc,
        private readonly ArtifactRepository $artifacts,
        private readonly BlobStore $blobs,
        private readonly StorageCapacityRepository $capacity,
    ) {}

    public function name(): string
    {
        return 'document_storage_cleanup';
    }

    public function reconcile(): int
    {
        $rows = $this->capacity->cleanupCandidates(afterId: $this->cursor);
        if ($rows === [] && $this->cursor !== null) {
            $this->cursor = null;
            $rows = $this->capacity->cleanupCandidates();
        }
        if ($rows === []) {
            $this->cursor = null;
        }
        $cleaned = 0;
        foreach ($rows as $candidate) {
            $reservation = $candidate->reservation;
            $this->cursor = $reservation->id;
            try {
                $cleaned += (int) ($candidate->writing
                    ? $this->workspace->cleanupOrStoppedFile($reservation)
                    : $this->gc->cleanup($reservation));
            } catch (\LogicException $invariant) {
                throw $invariant;
            } catch (\Throwable $failure) {
                ProcessLog::daemonError('reconcile', 'document_storage_reservation_cleanup_failed', $failure, [
                    'reservation_id' => $reservation->id->value,
                    'disk' => $reservation->disk,
                ]);
            }
        }
        if (count($rows) < 50) {
            $this->cursor = null;
        }

        $repairs = $this->artifacts->repairCandidates(afterId: $this->repairCursor);
        if ($repairs === [] && $this->repairCursor !== null) {
            $this->repairCursor = null;
            $repairs = $this->artifacts->repairCandidates();
        }
        foreach ($repairs as $blob) {
            $this->repairCursor = $blob->id;
            try {
                $cleaned += (int) $this->blobs->recoverStoppedRepair($blob);
            } catch (\LogicException $invariant) {
                throw $invariant;
            } catch (\Throwable $failure) {
                ProcessLog::daemonError('reconcile', 'document_storage_repair_recovery_failed', $failure, [
                    'blob_id' => $blob->id->value,
                    'disk' => $blob->disk,
                ]);
            }
        }
        if (count($repairs) < 25) {
            $this->repairCursor = null;
        }

        $candidates = $this->artifacts->unreferencedBatch(900, 25, $this->blobVerifiedAtCursor, $this->blobIdCursor);
        if ($candidates === [] && $this->blobIdCursor !== null) {
            $this->blobVerifiedAtCursor = null;
            $this->blobIdCursor = null;
            $candidates = $this->artifacts->unreferencedBatch(900, 25);
        }
        foreach ($candidates as $candidate) {
            $blob = $candidate->blob;
            $this->blobVerifiedAtCursor = $candidate->verifiedAt;
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
}
