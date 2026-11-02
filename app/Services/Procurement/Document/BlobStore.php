<?php

declare(strict_types=1);

namespace App\Services\Procurement\Document;

use App\Dto\Procurement\Document\StorageReservationRequest;
use App\Dto\Procurement\Document\VerifiedArtifact;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Enums\Procurement\Document\StoragePhase;
use App\Exceptions\Procurement\Document\ArtifactUnavailable;
use App\Repositories\Procurement\Document\StorageCapacityRepository;
use App\Repositories\Procurement\Process\LedgerRow;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;

/** Platform-free bounded immutable uploads. An uncertain write never releases its reservation. */
final readonly class BlobStore
{
    public function __construct(private StorageCapacityRepository $capacity, private ArtifactGarbageCollector $gc,
        private PrepareWorkspace $workspace) {}

    /** @param resource $source */
    public function put(StorageReservationRequest $reservation, $source, StorageReservationRequest $temporary, ?ExecutionContext $execution = null, bool $cleanupTemporary = true): VerifiedArtifact
    {
        $this->outsideTransaction();
        if (! is_resource($source) || get_resource_type($source) !== 'stream') {
            throw new \InvalidArgumentException('Expected a readable stream.');
        }
        if ($temporary->domainId == $reservation->domainId || $temporary->byteUpperBound < $reservation->byteUpperBound
            || ! Storage::disk($temporary->disk)->getAdapter() instanceof LocalFilesystemAdapter) {
            throw new \InvalidArgumentException('Upload spool needs a separate local temporary capacity reservation.');
        }
        $prepareSpool = $temporary->phase === StoragePhase::Prepare;
        $workspace = $prepareSpool ? $this->workspace->open($temporary) : null;
        if (! $prepareSpool) {
            $this->capacity->beginWrite($temporary);
        }
        $spool = null;
        try {
            $tmpDisk = Storage::disk($temporary->disk);
            if (! $tmpDisk->makeDirectory(dirname($temporary->objectKey()))) {
                throw new ArtifactUnavailable('Temporary directory unavailable.');
            }
            $spool = fopen($workspace === null ? $tmpDisk->path($temporary->objectKey()) : $workspace.'/spool', 'x+b');
            if ($spool === false) {
                throw new ArtifactUnavailable('Temporary storage unavailable.');
            }
            $size = 0;
            $hash = hash_init('sha256');
            while (! feof($source)) {
                $execution?->tick();
                $chunk = fread($source, max(1, min(8192, $reservation->byteUpperBound - $size + 1)));
                if ($chunk === false || ($chunk === '' && ! feof($source))) {
                    throw new ArtifactUnavailable('Source stream failed.');
                }
                $size += strlen($chunk);
                if ($size > $reservation->byteUpperBound) {
                    throw new ArtifactUnavailable('Stream exceeds its reserved byte ceiling.');
                }
                hash_update($hash, $chunk);
                if (fwrite($spool, $chunk) !== strlen($chunk)) {
                    throw new ArtifactUnavailable('Temporary storage write failed.');
                }
            }
            $expected = new ContentHash(hash_final($hash));
            $this->capacity->beginWrite($reservation);
            try {
                $disk = Storage::disk($reservation->disk);
                if ($disk->exists($reservation->objectKey())) {
                    throw new ArtifactUnavailable('Immutable upload key already exists.');
                }
                rewind($spool);
                if (! $disk->put($reservation->objectKey(), $spool)) {
                    throw new ArtifactUnavailable('Object write unconfirmed.');
                }
            } finally {
                // If PHP dies before this returns, writing remains charged; expiry/cancel is not cleanup.
                $this->capacity->finishWrite($reservation);
            }

            return $this->verify($reservation, $expected, $size, $execution);
        } finally {
            if (is_resource($spool)) {
                fclose($spool);
            }
            if ($prepareSpool) {
                if ($cleanupTemporary) {
                    $this->workspace->finish($temporary);
                }
            } else {
                $this->capacity->finishWrite($temporary);
                if ($cleanupTemporary) {
                    $this->gc->cleanup($temporary);
                }
            }
        }
    }

    public function verify(StorageReservationRequest $reservation, ContentHash $expected, int $size, ?ExecutionContext $execution = null): VerifiedArtifact
    {
        $this->outsideTransaction();
        $this->capacity->assertStaged($reservation);
        $stream = Storage::disk($reservation->disk)->readStream($reservation->objectKey());
        if (! is_resource($stream)) {
            throw new ArtifactUnavailable('Immutable object unavailable.');
        }
        try {
            $hash = hash_init('sha256');
            $actual = 0;
            while (! feof($stream)) {
                $execution?->tick();
                $chunk = fread($stream, 8192);
                if ($chunk === false || ($chunk === '' && ! feof($stream))) {
                    throw new ArtifactUnavailable('Object verification read failed.');
                }
                $actual += strlen($chunk);
                if ($actual > $reservation->byteUpperBound) {
                    throw new ArtifactUnavailable('Stored object exceeds reservation.');
                }
                hash_update($hash, $chunk);
            }
            if ($actual !== $size || hash_final($hash) !== $expected->hex) {
                throw new ArtifactUnavailable('Immutable object verification failed.');
            }

            return new VerifiedArtifact($reservation, $expected, $size);
        } finally {
            fclose($stream);
        }
    }

    /** Verify/repair a deduplicated object's immutable bytes before the DB-only ref acquisition gate. */
    public function verifyExisting(VerifiedArtifact $artifact, ?ExecutionContext $execution = null): void
    {
        $this->outsideTransaction();
        $row = LedgerRow::one("SELECT disk,object_key,size_bytes FROM prc_document_blobs
            WHERE storage_domain_id=? AND content_hash=decode(?,'hex') AND deletion_marked_at IS NULL",
            [$artifact->reservation->domainId->value, $artifact->hash->hex]);
        if ($row === null) {
            return;
        }
        $disk = Storage::disk($row->string('disk'));
        $key = $row->string('object_key');
        if ($this->matches($disk, $key, $artifact, $execution)) {
            return;
        }
        // Recovery writes the exact same immutable content, never another revision under this key.
        $source = Storage::disk($artifact->reservation->disk)->readStream($artifact->reservation->objectKey());
        if (! is_resource($source)) {
            throw new ArtifactUnavailable('Verified repair source unavailable.');
        }
        try {
            $execution?->tick();
            if (! $disk->put($key, $source) || ! $this->matches($disk, $key, $artifact, $execution)) {
                throw new ArtifactUnavailable('Immutable duplicate repair unconfirmed.');
            }
        } finally {
            fclose($source);
        }
    }

    private function matches(Filesystem $disk, string $key, VerifiedArtifact $artifact, ?ExecutionContext $execution): bool
    {
        if (! $disk->exists($key)) {
            return false;
        }
        $stream = $disk->readStream($key);
        if (! is_resource($stream)) {
            return false;
        }
        try {
            $hash = hash_init('sha256');
            $bytes = 0;
            while (! feof($stream)) {
                $execution?->tick();
                $chunk = fread($stream, 8192);
                if ($chunk === false || ($chunk === '' && ! feof($stream))) {
                    throw new ArtifactUnavailable('Immutable duplicate verification failed.');
                }
                $bytes += strlen($chunk);
                if ($bytes > $artifact->sizeBytes) {
                    return false;
                }
                hash_update($hash, $chunk);
            }

            return $bytes === $artifact->sizeBytes && hash_final($hash) === $artifact->hash->hex;
        } finally {
            fclose($stream);
        }
    }

    private function outsideTransaction(): void
    {
        if (DB::connection()->transactionLevel() !== 0) {
            throw new \LogicException('Object I/O must run outside database transactions.');
        }
    }
}
