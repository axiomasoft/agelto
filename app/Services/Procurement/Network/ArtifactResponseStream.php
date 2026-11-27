<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use App\Dto\Procurement\Process\Outcomes\ArtifactRef;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\StreamInterface;

/** Owns the transport spool until the surviving legacy reader closes its response. */
final class ArtifactResponseStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private bool $closed = false;

    public function __construct(private readonly ArtifactRef $artifact)
    {
        try {
            $resource = Storage::disk($artifact->disk)->readStream($artifact->key);
        } catch (\Throwable $failure) {
            $this->closed = true;
            $this->deleteArtifactSafely();
            throw new DependencyUnavailable('outbound_spool_unavailable', $failure);
        }
        if (! is_resource($resource)) {
            $this->closed = true;
            $this->deleteArtifactSafely();
            throw new DependencyUnavailable('outbound_spool_unavailable');
        }
        $this->stream = new Stream($resource);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        try {
            if (isset($this->stream)) {
                $this->stream->close();
            }
        } finally {
            $this->deleteArtifactSafely();
        }
    }

    /** @return resource|null */
    public function detach()
    {
        if ($this->closed) {
            return null;
        }
        $this->closed = true;
        try {
            return isset($this->stream) ? $this->stream->detach() : null;
        } finally {
            $this->deleteArtifactSafely();
        }
    }

    public function __destruct()
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        try {
            if (isset($this->stream)) {
                $this->stream->close();
            }
        } catch (\Throwable) {
        }
        if (DB::transactionLevel() === 0) {
            $this->deleteArtifactSafely();
        }
    }

    private function deleteArtifactSafely(): void
    {
        try {
            Storage::disk($this->artifact->disk)->delete($this->artifact->key);
        } catch (\Throwable) {
        }
    }
}
