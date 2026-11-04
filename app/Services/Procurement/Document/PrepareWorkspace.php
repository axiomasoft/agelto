<?php

declare(strict_types=1);

namespace App\Services\Procurement\Document;

use App\Dto\Procurement\Document\BlobReference;
use App\Dto\Procurement\Document\StorageReservationRequest;
use App\Enums\Procurement\Document\StoragePhase;
use App\Exceptions\Procurement\Document\ArtifactUnavailable;
use App\Repositories\Procurement\Document\StorageCapacityRepository;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;

/** Local stop evidence, not lease expiry, controls release of converter tmp capacity. */
final readonly class PrepareWorkspace
{
    /** Bounded owner.json, php-finished and cleanup proof in addition to spool bytes. */
    public const int SPOOL_PROOF_BYTES = 1024;

    public function __construct(private StorageCapacityRepository $capacity, private ArtifactGarbageCollector $gc) {}

    public function open(StorageReservationRequest $reservation): string
    {
        $disk = Storage::disk($reservation->disk);
        if ($reservation->phase !== StoragePhase::Prepare || ! $disk->getAdapter() instanceof LocalFilesystemAdapter) {
            throw new \InvalidArgumentException('Local prepare workspace required.');
        }
        $this->capacity->beginWrite($reservation);
        if (! $disk->makeDirectory($reservation->objectKey())) {
            throw new ArtifactUnavailable('Prepare workspace unavailable.');
        }
        $dir = $disk->path($reservation->objectKey());
        $owner = ['reservation' => $reservation->id->value, 'pid' => $this->pid(), 'start' => $this->start($this->pid())];
        if (file_put_contents($dir.'/owner.json', json_encode($owner, JSON_THROW_ON_ERROR)) === false) {
            throw new ArtifactUnavailable('Workspace owner proof unavailable.');
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
        if (! is_dir($dir)) {
            if (is_file($cleanupProof) && trim((string) file_get_contents($cleanupProof)) === $reservation->id->value) {
                $this->capacity->finishStoppedWrite($reservation);
            }

            // A missing proof cannot stop a writing reservation. A previously acknowledged stop
            // may finish cleanup after a crash, or replay after its proof has been deleted.
            return $this->gc->cleanup($reservation);
        }
        if (! is_file($dir.'/owner.json')) {
            return false;
        }
        $owner = json_decode((string) file_get_contents($dir.'/owner.json'), true, flags: JSON_THROW_ON_ERROR);
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
            $proof = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
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
            return false;
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
        $pid = $this->pid();
        $owner = [
            'reservation' => $reservation->id->value,
            'owner_token' => $reservation->ownerToken->value,
            'generation' => $reservation->generation,
            'key' => $reservation->objectKey(),
            'pid' => $pid,
            'start' => $this->start($pid),
        ];
        if (file_put_contents($disk->path($reservation->objectKey()).'.owner', json_encode($owner, JSON_THROW_ON_ERROR)) === false) {
            throw new ArtifactUnavailable('Local writer owner proof unavailable.');
        }
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
        try {
            $owner = json_decode((string) file_get_contents($ownerPath), true, flags: JSON_THROW_ON_ERROR);
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
