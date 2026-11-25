<?php

declare(strict_types=1);

namespace App\Services\Procurement\Document;

use App\Dto\Procurement\Document\BlobReference;
use App\Dto\Procurement\Document\StorageReservationRequest;
use App\Dto\Procurement\Document\StoredBlobUpload;
use App\Dto\Procurement\Document\VerifiedArtifact;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Document\StoragePhase;
use App\Exceptions\Procurement\Document\ArtifactUnavailable;
use App\Repositories\Procurement\Document\ArtifactRepository;
use App\Repositories\Procurement\Document\StorageCapacityRepository;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Support\Files\ShardedBlobPath;
use Aws\CommandInterface;
use Aws\Middleware;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;

/** Platform-free bounded immutable uploads. An uncertain write never releases its reservation. */
final readonly class BlobStore
{
    public function __construct(private StorageCapacityRepository $capacity, private ArtifactGarbageCollector $gc,
        private PrepareWorkspace $workspace, private ArtifactRepository $artifacts) {}

    /**
     * @param  resource  $source
     * @return ($reservation is StoredBlobUpload ? string : VerifiedArtifact)
     */
    public function put(StorageReservationRequest|StoredBlobUpload $reservation, $source, ?StorageReservationRequest $temporary = null, ?ExecutionContext $execution = null, bool $cleanupTemporary = true): VerifiedArtifact|string
    {
        $this->outsideTransaction();
        if (! is_resource($source) || get_resource_type($source) !== 'stream') {
            throw new \InvalidArgumentException('Expected a readable stream.');
        }
        if ($reservation instanceof StoredBlobUpload) {
            return $this->putStored($reservation, $source);
        }
        if ($temporary === null || $temporary->domainId == $reservation->domainId || $temporary->byteUpperBound < $reservation->byteUpperBound
            || ! Storage::disk($temporary->disk)->getAdapter() instanceof LocalFilesystemAdapter) {
            throw new \InvalidArgumentException('Upload spool needs a separate local temporary capacity reservation.');
        }
        $prepareSpool = $temporary->phase === StoragePhase::Prepare;
        $workspace = $prepareSpool ? $this->workspace->open($temporary) : null;
        if (! $prepareSpool) {
            $this->capacity->beginWrite($temporary);
            $this->workspace->recordFileWriter($temporary);
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
            $disk = Storage::disk($reservation->disk);
            $remote = ! $disk->getAdapter() instanceof LocalFilesystemAdapter
                && config("filesystems.disks.{$reservation->disk}.driver") === 's3';
            if ($remote && $reservation->attemptId === null) {
                throw new ArtifactUnavailable('Remote upload requires a work attempt.');
            }
            $putStarted = false;
            $confirmed = false;
            $execution?->tick();
            $this->capacity->beginWrite($reservation);
            try {
                $this->workspace->recordFileWriter($reservation);
                if ($disk->exists($reservation->objectKey())) {
                    throw new ArtifactUnavailable('Immutable upload key already exists.');
                }
                if (! rewind($spool)) {
                    throw new ArtifactUnavailable('Temporary upload stream cannot be rewound.');
                }
                $execution?->tick();
                $options = $remote ? $this->remoteWriteOptions($reservation->disk, $reservation, $spool, $size) : [];
                $putStarted = true;
                $confirmed = $remote
                    ? $disk->put($reservation->objectKey(), $spool, $options)
                    : $disk->put($reservation->objectKey(), $spool);
                if (! $confirmed) {
                    throw new ArtifactUnavailable('Object write unconfirmed.');
                }
            } finally {
                // A failed remote response can leave a request in flight after the caller exits.
                if (! $remote || ! $putStarted || $confirmed) {
                    $this->capacity->finishWrite($reservation);
                    $this->workspace->clearFileWriter($reservation);
                }
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
                $this->workspace->clearFileWriter($temporary);
                if ($cleanupTemporary) {
                    $this->gc->cleanup($temporary);
                }
            }
        }
    }

    /** @param resource $source */
    private function putStored(StoredBlobUpload $upload, $source): string
    {
        $path = ShardedBlobPath::for($upload->hash->hex);
        $disk = Storage::disk($upload->disk);
        $lock = @fopen(sys_get_temp_dir()."/agelto_archive_blob_{$upload->hash->hex}.lock", 'c');
        if (! is_resource($lock)) {
            throw new ArtifactUnavailable("Не удалось открыть блокировку записи блоба: {$path}");
        }

        try {
            if (! flock($lock, LOCK_EX)) {
                throw new ArtifactUnavailable("Не удалось получить блокировку записи блоба: {$path}");
            }
            if (! $disk->exists($path) && (! rewind($source) || ! $disk->put($path, $source))) {
                throw new ArtifactUnavailable("Не удалось сохранить запись архива в object storage: {$path}");
            }

            $reader = $disk instanceof AwsS3V3Adapter
                ? Storage::build([...$disk->getConfig(), 'stream_reads' => true])
                : $disk;
            if (! $this->matchesBlob(disk: $reader, key: $path, expectedHash: $upload->hash, expectedSize: $upload->sizeBytes, execution: null)) {
                throw new ArtifactUnavailable("Object storage вернул неполную или другую запись архива: {$path}");
            }

            return $path;
        } finally {
            fclose($lock);
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
        $row = LedgerRow::one("SELECT id,disk,object_key,size_bytes FROM prc_document_blobs
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
        $source = Storage::disk($artifact->reservation->disk)->readStream($artifact->reservation->objectKey());
        if (! is_resource($source)) {
            throw new ArtifactUnavailable('Verified repair source unavailable.');
        }
        $blob = new BlobReference(new ProcessUuid($row->string('id')), $artifact->reservation->domainId,
            $artifact->hash, $row->string('disk'), $key, $row->int('size_bytes'));
        $confirmed = false;
        $remote = ! $disk->getAdapter() instanceof LocalFilesystemAdapter
            && config("filesystems.disks.{$blob->disk}.driver") === 's3';
        $claim = null;
        try {
            $execution?->tick();
            $options = $remote ? $this->remoteWriteOptions($blob->disk, $artifact->reservation, $source, $artifact->sizeBytes) : [];
            // Re-read and pin under the capacity authority before touching the older immutable key.
            // A completed GC means the caller will acquire its own fresh staging object instead.
            if ($remote) {
                $claim = $this->artifacts->beginRemoteRepair($blob, source: $artifact->reservation);
                if ($claim === null) {
                    return;
                }
            } elseif (! $this->artifacts->beginRepair($blob)) {
                return;
            }
            $this->workspace->recordRepairWriter($blob);
            $confirmed = $remote ? $disk->put($key, $source, $options) : $disk->put($key, $source);
            if (! $confirmed || ! $this->matches($disk, $key, $artifact, $execution)) {
                throw new ArtifactUnavailable('Immutable duplicate repair unconfirmed.');
            }
        } finally {
            fclose($source);
            if ($confirmed) {
                $this->workspace->clearRepairWriter($blob);
                if ($claim === null) {
                    $this->artifacts->finishRepair($blob);
                } elseif (! $this->artifacts->finishRemoteRepair($blob, claim: $claim)) {
                    throw new ArtifactUnavailable('Canonical repair ownership changed.');
                }
            }
        }
    }

    /**
     * @param  resource  $source
     * @return array{mup_threshold: int, before_upload: \Closure(CommandInterface): void}
     */
    private function remoteWriteOptions(string $disk, StorageReservationRequest $writer, $source, int $size): array
    {
        $timeout = config("filesystems.disks.{$disk}.http.timeout");
        if ((! is_float($timeout) && ! is_int($timeout)) || ! is_finite($timeout) || $timeout <= 0) {
            throw new ArtifactUnavailable('Remote upload requires a finite HTTP timeout.');
        }
        if ((fstat($source)['size'] ?? null) !== $size) {
            throw new ArtifactUnavailable('Remote upload requires a stream of the verified size.');
        }
        $this->assertRemoteWriter($writer);

        // Recovery waits for one request timeout after the lease. An unknown size, multipart
        // upload or SDK retry could extend writes past that window.
        return [
            'mup_threshold' => PHP_INT_MAX,
            'before_upload' => function (CommandInterface $command) use ($writer, $timeout): void {
                $command['@retries'] = 0;
                $httpOptions = $command['@http'];
                $command['@http'] = ['timeout' => $timeout] + (is_array($httpOptions) ? $httpOptions : []);
                $command->getHandlerList()->appendAttempt(Middleware::tap(fn () => $this->assertRemoteWriter($writer)));
            },
        ];
    }

    private function assertRemoteWriter(StorageReservationRequest $writer): void
    {
        if (LedgerRow::one(
            sql: "SELECT 1 FROM prc_process_attempts a JOIN prc_process_work w ON w.id = a.work_id
                WHERE a.id = ? AND w.id = ? AND a.generation = ? AND a.finished_at IS NULL
                AND w.generation = a.generation AND w.owner_token = ? AND w.state = 'running'
                AND w.lease_until > clock_timestamp()",
            bindings: [$writer->attemptId?->value, $writer->workId?->value, $writer->generation, $writer->ownerToken->value],
        ) === null) {
            throw new ArtifactUnavailable('Remote upload requires a live writer lease.');
        }
    }

    public function recoverStoppedRepair(BlobReference $blob): bool
    {
        $this->outsideTransaction();
        $disk = Storage::disk($blob->disk);
        if (! $disk->getAdapter() instanceof LocalFilesystemAdapter && config("filesystems.disks.{$blob->disk}.driver") === 's3') {
            $claim = $this->artifacts->remoteRepairClaim($blob);
            if ($claim === null) {
                return false;
            }
            $claimedAt = $claim->string('repair_claimed_at');
            $attempt = $claim->nullableString('repair_attempt_id');

            // A subsequent writer can retry a missing or incomplete repair; the canonical key remains catalogued.
            return $this->workspace->remoteWriterStopped($blob->disk, $attempt === null ? null : new ProcessUuid($attempt), unownedSince: $claimedAt)
                && $this->artifacts->finishRemoteRepair($blob, claim: $claimedAt);
        }
        if (! $this->workspace->repairWriterStopped($blob)) {
            return false;
        }
        if (! $this->matchesBlob($disk, $blob->objectKey, $blob->hash, $blob->sizeBytes, null)) {
            return false;
        }
        $this->workspace->clearRepairWriter($blob);
        $this->artifacts->finishRepair($blob);

        return true;
    }

    private function matches(Filesystem $disk, string $key, VerifiedArtifact $artifact, ?ExecutionContext $execution): bool
    {
        return $this->matchesBlob($disk, $key, $artifact->hash, $artifact->sizeBytes, $execution);
    }

    private function matchesBlob(Filesystem $disk, string $key, ContentHash $expectedHash, int $expectedSize, ?ExecutionContext $execution): bool
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
                if ($bytes > $expectedSize) {
                    return false;
                }
                hash_update($hash, $chunk);
            }

            return $bytes === $expectedSize && hash_final($hash) === $expectedHash->hex;
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
