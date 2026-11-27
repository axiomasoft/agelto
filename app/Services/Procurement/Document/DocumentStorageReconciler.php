<?php

declare(strict_types=1);

namespace App\Services\Procurement\Document;

use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Repositories\Procurement\Document\ArtifactGcRepository;
use App\Repositories\Procurement\Document\ArtifactRepairRepository;
use App\Repositories\Procurement\Document\StorageCapacityRepository;
use App\Services\Procurement\Process\Contracts\ReconcileCategory;
use App\Services\Procurement\Process\Infrastructure\ProcessLog;

/** Object I/O stays outside DB locks. A dead lease alone never releases uncertain tool bytes. */
final class DocumentStorageReconciler implements ReconcileCategory
{
    private const int RESERVATION_BATCH = 50;

    private const int BLOB_BATCH = 25;

    private ?ProcessUuid $cursor = null;

    private ?ProcessUuid $repairCursor = null;

    private ?string $blobVerifiedAtCursor = null;

    private ?ProcessUuid $blobIdCursor = null;

    public function __construct(
        private readonly PrepareWorkspace $workspace,
        private readonly ArtifactGarbageCollector $gc,
        private readonly ArtifactRepairRepository $repairs,
        private readonly ArtifactGcRepository $gcRepository,
        private readonly BlobStore $blobs,
        private readonly StorageCapacityRepository $capacity,
    ) {}

    public function name(): string
    {
        return 'document_storage_cleanup';
    }

    public function reconcile(): int
    {
        $rows = $this->capacity->cleanupCandidates(afterId: $this->cursor, limit: self::RESERVATION_BATCH);
        if ($rows === [] && $this->cursor !== null) {
            $this->cursor = null;
            $rows = $this->capacity->cleanupCandidates(limit: self::RESERVATION_BATCH);
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
        if (count($rows) < self::RESERVATION_BATCH) {
            $this->cursor = null;
        }

        $repairs = $this->repairs->repairCandidates(afterId: $this->repairCursor, limit: self::BLOB_BATCH);
        if ($repairs === [] && $this->repairCursor !== null) {
            $this->repairCursor = null;
            $repairs = $this->repairs->repairCandidates(limit: self::BLOB_BATCH);
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
        if (count($repairs) < self::BLOB_BATCH) {
            $this->repairCursor = null;
        }

        $candidates = $this->gcRepository->unreferencedBatch(graceSeconds: 900, limit: self::BLOB_BATCH,
            afterVerifiedAt: $this->blobVerifiedAtCursor, afterId: $this->blobIdCursor);
        if ($candidates === [] && $this->blobIdCursor !== null) {
            $this->blobVerifiedAtCursor = null;
            $this->blobIdCursor = null;
            $candidates = $this->gcRepository->unreferencedBatch(graceSeconds: 900, limit: self::BLOB_BATCH);
        }
        foreach ($candidates as $candidate) {
            $blob = $candidate->blob;
            $this->blobVerifiedAtCursor = $candidate->verifiedAt;
            $this->blobIdCursor = $blob->id;
            try {
                $mark = $this->gcRepository->markDeletion($blob, 900);
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
        if (count($candidates) < self::BLOB_BATCH) {
            $this->blobVerifiedAtCursor = null;
            $this->blobIdCursor = null;
        }

        return $cleaned;
    }
}
