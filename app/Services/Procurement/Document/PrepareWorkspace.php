<?php

declare(strict_types=1);

namespace App\Services\Procurement\Document;

use App\Dto\Procurement\Document\StorageReservationRequest;
use App\Enums\Procurement\Document\StoragePhase;
use App\Exceptions\Procurement\Document\ArtifactUnavailable;
use App\Repositories\Procurement\Document\StorageCapacityRepository;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;

/** Local stop evidence, not lease expiry, controls release of converter tmp capacity. */
final readonly class PrepareWorkspace
{
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
        if (! is_dir($dir) && is_file($cleanupProof) && trim((string) file_get_contents($cleanupProof)) === $reservation->id->value) {
            $this->capacity->finishStoppedWrite($reservation);

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
