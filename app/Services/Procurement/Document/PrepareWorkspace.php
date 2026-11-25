<?php

declare(strict_types=1);

namespace App\Services\Procurement\Document;

use App\Dto\Procurement\Document\BlobReference;
use App\Dto\Procurement\Document\StorageReservationRequest;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Document\StoragePhase;
use App\Enums\Procurement\Process\StageCode;
use App\Exceptions\Procurement\Document\ArtifactUnavailable;
use App\Repositories\Procurement\Document\StorageCapacityRepository;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Procurement\Process\ProcessLog;
use App\Services\Procurement\Process\ProcessPolicy;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;

/** Local stop evidence, not lease expiry, controls release of converter tmp capacity. */
final readonly class PrepareWorkspace
{
    /** Bounded owner.json, php-finished and cleanup proof in addition to spool bytes. */
    public const int SPOOL_PROOF_BYTES = 1024;

    private const int S3_GRACE_SECONDS = 30;

    public function __construct(private StorageCapacityRepository $capacity, private ArtifactGarbageCollector $gc, private ProcessPolicy $policy) {}

    public function open(StorageReservationRequest $reservation): string
    {
        $disk = Storage::disk($reservation->disk);
        if ($reservation->phase !== StoragePhase::Prepare || ! $disk->getAdapter() instanceof LocalFilesystemAdapter) {
            throw new \InvalidArgumentException('Local prepare workspace required.');
        }
        $key = $reservation->objectKey();
        $dir = $disk->path($key);
        $cleanupOnFailure = true;
        $writing = false;
        try {
            if (! $disk->makeDirectory(dirname($key))) {
                throw new ArtifactUnavailable('Prepare workspace unavailable.');
            }
            $cleanupOnFailure = false;
            // The parent survives workspace cleanup, so concurrent opens lock the same directory.
            $lock = @fopen(dirname($dir), 'r');
            if (! is_resource($lock)) {
                throw new ArtifactUnavailable('Prepare workspace initialization lock unavailable.');
            }
            try {
                if (! flock($lock, LOCK_EX | LOCK_NB)) {
                    throw new ArtifactUnavailable('Prepare workspace initialization lock unavailable.');
                }
                $row = LedgerRow::one('SELECT * FROM prc_storage_reservations WHERE id=?', [$reservation->id->value])
                    ?? throw new \LogicException('Missing reservation.');
                $this->capacity->assertIdentity($reservation, $row);
                if ($row->string('status') !== 'reserved') {
                    throw new \LogicException('Upload already claimed; verify or reconcile instead of overwriting.');
                }
                $cleanupOnFailure = true;
                // Publish a recoverable writer identity before there can be a writing reservation.
                $this->writeFileOwner($reservation, $dir.'.owner');
                $this->capacity->beginWrite($reservation);
                $writing = true;
                if (! $disk->makeDirectory($key)) {
                    throw new ArtifactUnavailable('Prepare workspace unavailable.');
                }
                if (! @rename($dir.'.owner', $dir.'/owner.json')) {
                    throw new ArtifactUnavailable('Workspace owner proof unavailable.');
                }
            } finally {
                fclose($lock);
            }
        } catch (\Throwable $failure) {
            // No caller has the workspace path yet, so no tool or spool writer can still use it.
            if ($cleanupOnFailure) {
                try {
                    if ($writing) {
                        $this->capacity->finishStoppedWrite($reservation);
                    }
                    $this->gc->cleanup($reservation);
                } catch (\Throwable $cleanupFailure) {
                    ProcessLog::dependencyUnavailable('storage', $cleanupFailure);
                }
            }

            throw $failure;
        }

        return $dir;
    }

    /** The handler has left its tool/copy chain; remaining work belongs only to the supervisor. */
    public function finish(StorageReservationRequest $reservation): bool
    {
        $dir = Storage::disk($reservation->disk)->path($reservation->objectKey());
        if (is_dir($dir) && file_put_contents($dir.'/php-finished', $reservation->id->value) === false) {
            return false;
        }

        return $this->cleanup($reservation);
    }

    /** Safe to retry after a PHP kill and wrapper deadline; active PHP identity or tool marker holds bytes. */
    public function cleanup(StorageReservationRequest $reservation): bool
    {
        $disk = Storage::disk($reservation->disk);
        if (! $disk->getAdapter() instanceof LocalFilesystemAdapter || $reservation->phase !== StoragePhase::Prepare) {
            throw new \InvalidArgumentException('Local prepare workspace required.');
        }
        $dir = $disk->path($reservation->objectKey());
        $cleanupProof = $dir.'.cleanup';
        if (! is_file($dir.'/owner.json')) {
            if (is_dir($dir) || is_file($dir.'.owner')) {
                return $this->cleanupStoppedFile($reservation);
            }
            if (is_file($cleanupProof) && trim((string) file_get_contents($cleanupProof)) === $reservation->id->value) {
                $this->capacity->finishStoppedWrite($reservation);
            }

            // A missing proof cannot stop a writing reservation. A previously acknowledged stop
            // may finish cleanup after a crash, or replay after its proof has been deleted.
            return $this->gc->cleanup($reservation);
        }
        try {
            $owner = json_decode((string) file_get_contents($dir.'/owner.json'), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        if (! is_array($owner) || ($owner['reservation'] ?? null) !== $reservation->id->value || ! is_int($owner['pid'] ?? null) || ! is_string($owner['start'] ?? null)) {
            return false;
        }
        $finished = is_file($dir.'/php-finished') && trim((string) file_get_contents($dir.'/php-finished')) === $reservation->id->value;
        if (! $finished && $owner['pid'] !== $this->pid() && $this->start($owner['pid']) === $owner['start']) {
            return false;
        }
        if (glob($dir.'/*.active') !== []) {
            return false;
        }
        foreach (glob($dir.'/tool-*') ?: [] as $path) {
            if (str_contains(basename($path), '.')) {
                continue;
            }
            try {
                $proof = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return false;
            }
            if (! is_array($proof) || ($proof['stopped'] ?? null) !== true) {
                return false;
            }
        }
        if (file_put_contents($cleanupProof, $reservation->id->value) === false) {
            return false;
        }
        if (! $disk->deleteDirectory($reservation->objectKey()) || $disk->exists($reservation->objectKey())) {
            return false;
        }
        $this->capacity->finishStoppedWrite($reservation);

        return $this->gc->cleanup($reservation);
    }

    public function cleanupOrStoppedFile(StorageReservationRequest $reservation): bool
    {
        $disk = Storage::disk($reservation->disk);
        if (! $disk->getAdapter() instanceof LocalFilesystemAdapter) {
            if ($reservation->attemptId === null || ! $this->remoteWriterStopped($reservation->disk, $reservation->attemptId)) {
                return false;
            }
            $this->capacity->finishStoppedWrite($reservation);

            return $this->gc->cleanup($reservation);
        }
        $path = $disk->path($reservation->objectKey());
        if ($reservation->phase === StoragePhase::Prepare && (is_dir($path) || (! is_file($path) && ! is_file($path.'.owner')))) {
            return $this->cleanup($reservation);
        }

        return $this->cleanupStoppedFile($reservation);
    }

    public function recordFileWriter(StorageReservationRequest $reservation): void
    {
        $disk = Storage::disk($reservation->disk);
        if (! $disk->getAdapter() instanceof LocalFilesystemAdapter) {
            return;
        }
        if (! $disk->makeDirectory(dirname($reservation->objectKey()))) {
            throw new ArtifactUnavailable('Local writer directory unavailable.');
        }
        $this->writeFileOwner($reservation, $disk->path($reservation->objectKey()).'.owner');
    }

    public function clearFileWriter(StorageReservationRequest $reservation): void
    {
        $disk = Storage::disk($reservation->disk);
        if (! $disk->getAdapter() instanceof LocalFilesystemAdapter) {
            return;
        }
        $ownerPath = $disk->path($reservation->objectKey()).'.owner';
        if (is_file($ownerPath)) {
            @unlink($ownerPath);
        }
    }

    public function cleanupStoppedFile(StorageReservationRequest $reservation): bool
    {
        $disk = Storage::disk($reservation->disk);
        if (! $disk->getAdapter() instanceof LocalFilesystemAdapter) {
            return false;
        }
        $path = $disk->path($reservation->objectKey());
        $ownerPath = $path.'.owner';
        $cleanupProof = $path.'.cleanup';
        if (is_file($cleanupProof) && trim((string) file_get_contents($cleanupProof)) === $reservation->id->value) {
            if ((is_file($path) && ! @unlink($path)) || (is_file($ownerPath) && ! @unlink($ownerPath)) || is_file($path) || is_file($ownerPath)) {
                return false;
            }
            $this->capacity->finishStoppedWrite($reservation);

            return $this->gc->cleanup($reservation);
        }
        if (! is_file($ownerPath)) {
            return false;
        }
        $proof = @file_get_contents($ownerPath);
        if ($proof === false) {
            return false;
        }
        try {
            $owner = json_decode($proof, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        if (! is_array($owner)
            || ($owner['reservation'] ?? null) !== $reservation->id->value
            || ($owner['owner_token'] ?? null) !== $reservation->ownerToken->value
            || ($owner['generation'] ?? null) !== $reservation->generation
            || ($owner['key'] ?? null) !== $reservation->objectKey()
            || ! is_int($owner['pid'] ?? null)
            || ! is_string($owner['start'] ?? null)) {
            return false;
        }
        if ($owner['pid'] === $this->pid() || $this->start($owner['pid']) === $owner['start']) {
            return false;
        }
        // The owner may have published owner.json and started tools before it stopped.
        if (@file_get_contents($ownerPath) !== $proof) {
            return false;
        }
        if (file_put_contents($cleanupProof, $reservation->id->value) === false) {
            return false;
        }
        if ((is_file($path) && ! @unlink($path)) || (is_file($ownerPath) && ! @unlink($ownerPath)) || is_file($path) || is_file($ownerPath)) {
            return false;
        }
        $this->capacity->finishStoppedWrite($reservation);

        return $this->gc->cleanup($reservation);
    }

    public function recordRepairWriter(BlobReference $blob): void
    {
        $disk = Storage::disk($blob->disk);
        if (! $disk->getAdapter() instanceof LocalFilesystemAdapter) {
            return;
        }
        $pid = $this->pid();
        $owner = [
            'blob' => $blob->id->value,
            'key' => $blob->objectKey,
            'pid' => $pid,
            'start' => $this->start($pid),
        ];
        if (file_put_contents($disk->path($blob->objectKey).'.repair-owner', json_encode($owner, JSON_THROW_ON_ERROR)) === false) {
            throw new ArtifactUnavailable('Canonical repair owner proof unavailable.');
        }
    }

    public function clearRepairWriter(BlobReference $blob): void
    {
        $disk = Storage::disk($blob->disk);
        if (! $disk->getAdapter() instanceof LocalFilesystemAdapter) {
            return;
        }
        $ownerPath = $disk->path($blob->objectKey).'.repair-owner';
        if (is_file($ownerPath)) {
            @unlink($ownerPath);
        }
    }

    public function repairWriterStopped(BlobReference $blob): bool
    {
        $disk = Storage::disk($blob->disk);
        if (! $disk->getAdapter() instanceof LocalFilesystemAdapter) {
            return false;
        }
        $ownerPath = $disk->path($blob->objectKey).'.repair-owner';
        if (! is_file($ownerPath)) {
            return false;
        }
        try {
            $owner = json_decode((string) file_get_contents($ownerPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        if (! is_array($owner)
            || ($owner['blob'] ?? null) !== $blob->id->value
            || ($owner['key'] ?? null) !== $blob->objectKey
            || ! is_int($owner['pid'] ?? null)
            || ! is_string($owner['start'] ?? null)) {
            return false;
        }

        return $owner['pid'] !== $this->pid() && $this->start($owner['pid']) !== $owner['start'];
    }

    public function remoteWriterStopped(string $disk, ?ProcessUuid $attemptId, ?string $unownedSince = null): bool
    {
        $timeout = config("filesystems.disks.{$disk}.http.timeout");
        if (config("filesystems.disks.{$disk}.driver") !== 's3' || (! is_float($timeout) && ! is_int($timeout)) || ! is_finite($timeout) || $timeout <= 0) {
            return false;
        }
        $margin = (int) ceil($timeout) + self::S3_GRACE_SECONDS;
        if ($attemptId === null) {
            // Legacy repair holds have no owner. Observe the hold for a full worker lease.
            $maxLease = max(array_map($this->policy->leaseSeconds(...), StageCode::cases()));

            return $unownedSince !== null && (LedgerRow::one(
                'SELECT clock_timestamp() >= ?::timestamptz + make_interval(secs => ?) AS expired',
                [$unownedSince, $maxLease + $margin],
            )?->bool('expired') ?? false);
        }

        $attempt = LedgerRow::one("SELECT w.stage, clock_timestamp()::text AS db_now, a.finished_at,
            GREATEST(a.started_at, a.last_heartbeat_at, a.last_checkpoint_at) AS last_renewal,
            CASE WHEN w.state = 'running' AND w.generation = a.generation THEN w.lease_until END AS lease_until
            FROM prc_process_attempts a JOIN prc_process_work w ON w.id = a.work_id WHERE a.id = ?", [$attemptId->value]);
        if ($attempt === null) {
            return false;
        }
        $lease = $attempt->nullableString('lease_until');
        $finished = $attempt->nullableString('finished_at');
        $stageLease = $this->policy->leaseSeconds(StageCode::from($attempt->string('stage')));
        $until = $lease === null
            ? (new \DateTimeImmutable($attempt->string('last_renewal')))->modify("+{$stageLease} seconds")
            : new \DateTimeImmutable($lease);
        if ($finished !== null) {
            $until = max($until, new \DateTimeImmutable($finished));
        }

        return new \DateTimeImmutable($attempt->string('db_now')) >= $until->modify("+{$margin} seconds");
    }

    private function writeFileOwner(StorageReservationRequest $reservation, string $path): void
    {
        $pid = $this->pid();
        $start = $this->start($pid) ?? throw new ArtifactUnavailable('Local writer process identity unavailable.');
        $proof = json_encode([
            'reservation' => $reservation->id->value,
            'owner_token' => $reservation->ownerToken->value,
            'generation' => $reservation->generation,
            'key' => $reservation->objectKey(),
            'pid' => $pid,
            'start' => $start,
        ], JSON_THROW_ON_ERROR);
        if (@file_put_contents($path, $proof) !== strlen($proof)) {
            throw new ArtifactUnavailable('Local writer owner proof unavailable.');
        }
    }

    private function pid(): int
    {
        $pid = getmypid();

        return $pid === false ? throw new \RuntimeException('Local process identity unavailable.') : $pid;
    }

    private function start(int $pid): ?string
    {
        $file = '/proc/'.$pid.'/stat';
        $stat = is_file($file) ? file_get_contents($file) : false;
        if ($stat === false) {
            return null;
        }
        $tail = substr($stat, (int) strrpos($stat, ')') + 2);

        return explode(' ', $tail)[19] ?? null;
    }
}
