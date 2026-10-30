<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use App\Dto\Procurement\Process\ArtifactRef;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\StreamInterface;

/** Owns the transport spool until the surviving legacy reader closes its response. */
final class ArtifactResponseStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private bool $closed = false;

    public function __construct(private readonly ArtifactRef $artifact)
    {
        $resource = Storage::disk($artifact->disk)->readStream($artifact->key);
        if (! is_resource($resource)) {
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
            $this->stream->close();
        } finally {
            Storage::disk($this->artifact->disk)->delete($this->artifact->key);
        }
    }
}
