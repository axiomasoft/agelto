<?php

declare(strict_types=1);

namespace App\Services\Procurement\Document;

use App\Dto\Procurement\Document\StorageReservationRequest;
use App\Dto\Procurement\Document\VerifiedArtifact;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Exceptions\Procurement\Document\ArtifactUnavailable;
use App\Repositories\Procurement\Document\StorageCapacityRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;

/** Platform-free bounded immutable uploads. An uncertain write never releases its reservation. */
final readonly class BlobStore
{
    public function __construct(private StorageCapacityRepository $capacity, private ArtifactGarbageCollector $gc) {}

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
        $this->capacity->beginWrite($temporary);
        $spool = null;
        try {
            $tmpDisk = Storage::disk($temporary->disk);
            if (! $tmpDisk->makeDirectory(dirname($temporary->objectKey()))) {
                throw new ArtifactUnavailable('Temporary directory unavailable.');
            }
            $spool = fopen($tmpDisk->path($temporary->objectKey()), 'x+b');
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
            $this->capacity->finishWrite($temporary);
            if ($cleanupTemporary) {
                $this->gc->cleanup($temporary);
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

    private function outsideTransaction(): void
    {
        if (DB::connection()->transactionLevel() !== 0) {
            throw new \LogicException('Object I/O must run outside database transactions.');
        }
    }
}
