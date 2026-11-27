<?php

declare(strict_types=1);

namespace App\Services\Procurement\Acquisition\Detail;

use App\Dto\Procurement\Acquisition\Detail\ProcurementSnapshot;
use App\Dto\Procurement\Process\Outcomes\ArtifactRef;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;

/** Bounded immutable normalized parts. All storage I/O happens outside finalization. */
final readonly class DetailArtifacts
{
    public function __construct(private DetailValueCodec $codec, private string $disk = 'local') {}

    public function put(ProcurementSnapshot $snapshot): ArtifactRef
    {
        $bytes = $this->codec->encode($snapshot);
        $hash = ContentHash::of($bytes);
        $key = 'acquisition/parts/'.$hash->hex.'.json';
        try {
            if (! Storage::disk($this->disk)->put($key, $bytes)) {
                throw new DependencyUnavailable('storage');
            }
        } catch (FilesystemException $error) {
            throw new DependencyUnavailable('storage', $error);
        }
        $ref = new ArtifactRef($this->disk, $key, $hash);
        $this->read($ref);

        return $ref;
    }

    public function read(ArtifactRef $ref): ProcurementSnapshot
    {
        if ($ref->disk !== $this->disk || $ref->key !== 'acquisition/parts/'.$ref->sha256->hex.'.json') {
            throw new \InvalidArgumentException('Invalid detail artifact reference.');
        }
        try {
            $size = Storage::disk($ref->disk)->size($ref->key);
            if ($size > 2097152) {
                throw new \InvalidArgumentException('Oversized detail artifact.');
            }
            $bytes = Storage::disk($ref->disk)->get($ref->key);
        } catch (FilesystemException $error) {
            throw new DependencyUnavailable('storage', $error);
        }
        if ($bytes === null) {
            throw new DependencyUnavailable('storage');
        }
        if (ContentHash::of($bytes)->hex !== $ref->sha256->hex) {
            throw new \RuntimeException('Detail artifact integrity failure.');
        }
        $snapshot = $this->codec->decode($bytes);
        if (! $snapshot instanceof ProcurementSnapshot || ! $snapshot->complete()) {
            throw new \InvalidArgumentException('Invalid saved detail part.');
        }

        return $snapshot;
    }
}
