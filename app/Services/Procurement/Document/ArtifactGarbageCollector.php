<?php

declare(strict_types=1);

namespace App\Services\Procurement\Document;

use App\Dto\Procurement\Document\Storage\BlobDeletion;
use App\Dto\Procurement\Document\Storage\StorageReservationRequest;
use App\Enums\Procurement\Document\ArtifactDeletionState;
use App\Enums\Procurement\Document\StoragePhase;
use App\Repositories\Procurement\Document\ArtifactGcRepository;
use App\Support\Database\TransactionGuard;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;

final readonly class ArtifactGarbageCollector
{
    public function __construct(private ArtifactGcRepository $artifacts) {}

    /** Replaying a mark after crash is safe; references cannot be acquired while it is marked. */
    public function deleteMarked(BlobDeletion $deletion): bool
    {
        TransactionGuard::forbidActive('Artifact deletion');
        $state = $this->artifacts->deletionState($deletion);
        if ($state !== ArtifactDeletionState::Ready) {
            return $state === ArtifactDeletionState::Deleted;
        }
        $blob = $deletion->blob;
        $disk = Storage::disk($blob->disk);
        if (! $disk->delete($blob->objectKey) || $disk->exists($blob->objectKey)) {
            return false;
        }

        return $this->artifacts->finishDeletion($deletion);
    }

    /** Running/uncertain writer and transferred original are never cleaned via reservation expiry. */
    public function cleanup(StorageReservationRequest $reservation): bool
    {
        TransactionGuard::forbidActive('Artifact deletion');
        if (! $this->artifacts->beginCleanup($reservation)) {
            return false;
        }
        $disk = Storage::disk($reservation->disk);
        $local = $disk->getAdapter() instanceof LocalFilesystemAdapter;
        if ($local && $reservation->phase === StoragePhase::Prepare) {
            $parent = dirname($disk->path($reservation->objectKey()));
            // The cleaning status now fences any new initializer. Wait for the existing one to leave.
            clearstatcache(true, $parent);
            if (is_dir($parent)) {
                $lock = @fopen($parent, 'r');
                if (! is_resource($lock)) {
                    return false;
                }
                try {
                    return flock($lock, LOCK_EX | LOCK_NB) && $this->deleteReservation($disk, $reservation, local: true);
                } finally {
                    fclose($lock);
                }
            }
        }

        return $this->deleteReservation($disk, $reservation, $local);
    }

    private function deleteReservation(Filesystem $disk, StorageReservationRequest $reservation, bool $local): bool
    {
        if ($local && $reservation->phase === StoragePhase::Prepare && is_dir($disk->path($reservation->objectKey()))
            && ! $disk->deleteDirectory($reservation->objectKey())) {
            return false;
        }
        if (! $disk->delete($reservation->objectKey()) || $disk->exists($reservation->objectKey())) {
            return false;
        }
        if ($reservation->phase === StoragePhase::Prepare) {
            $proof = $reservation->objectKey().'.cleanup';
            if (! $disk->delete($proof) || $disk->exists($proof)) {
                return false;
            }
        }
        if ($local) {
            foreach (['.cleanup', '.owner'] as $suffix) {
                $sidecar = $disk->path($reservation->objectKey()).$suffix;
                if (is_file($sidecar) && (! @unlink($sidecar) || is_file($sidecar))) {
                    return false;
                }
            }
        }
        $this->artifacts->finishCleanup($reservation);

        return true;
    }
}
