<?php

declare(strict_types=1);

namespace App\Services\Procurement\Document;

use App\Dto\Procurement\Document\BlobDeletion;
use App\Dto\Procurement\Document\StorageReservationRequest;
use App\Enums\Procurement\Document\ArtifactDeletionState;
use App\Repositories\Procurement\Document\ArtifactRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final readonly class ArtifactGarbageCollector
{
    public function __construct(private ArtifactRepository $artifacts) {}

    /** Replaying a mark after crash is safe; references cannot be acquired while it is marked. */
    public function deleteMarked(BlobDeletion $deletion): bool
    {
        $this->outsideTransaction();
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
        $this->outsideTransaction();
        if (! $this->artifacts->beginCleanup($reservation)) {
            return false;
        }
        $disk = Storage::disk($reservation->disk);
        if (! $disk->delete($reservation->objectKey()) || $disk->exists($reservation->objectKey())) {
            return false;
        }
        $this->artifacts->finishCleanup($reservation);

        return true;
    }

    private function outsideTransaction(): void
    {
        if (DB::connection()->transactionLevel() !== 0) {
            throw new \LogicException('Artifact deletion must run outside database transactions.');
        }
    }
}
