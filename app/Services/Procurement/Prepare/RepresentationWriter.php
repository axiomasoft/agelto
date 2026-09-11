<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use App\Actions\Procurement\Document\FinalizeRepresentationAction;
use App\Enums\Procurement\Document\RepresentationKind;
use App\Models\Procurement\Document\DocumentRepresentation;
use App\Models\Procurement\Document\DocumentVersion;
use Illuminate\Contracts\Filesystem\Filesystem;

final readonly class RepresentationWriter
{
    public function __construct(private FinalizeRepresentationAction $finalize) {}

    /** @param array<string, mixed> $attributes */
    public function write(Filesystem $disk, DocumentVersion $version, RepresentationKind $kind, string $path, string $content, array $attributes): DocumentRepresentation
    {
        $stream = fopen('php://temp', 'w+b');

        if (! is_resource($stream)) {
            throw new \RuntimeException('Не удалось открыть временный поток representation.');
        }

        try {
            if (fwrite($stream, $content) !== strlen($content)) {
                throw new \RuntimeException('Не удалось записать representation во временный поток.');
            }

            return $this->writeStream(
                disk: $disk,
                version: $version,
                kind: $kind,
                path: $path,
                stream: $stream,
                attributes: $attributes,
            );
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  resource  $stream
     * @param  array<string, mixed>  $attributes
     */
    public function writeStream(Filesystem $disk, DocumentVersion $version, RepresentationKind $kind, string $path, mixed $stream, array $attributes): DocumentRepresentation
    {
        if (! is_resource($stream)) {
            throw new \InvalidArgumentException('Representation content must be a stream resource.');
        }

        $this->rewind($stream);
        [$contentHash] = $this->hash($stream);
        $storagePath = $this->contentAddressedPath(path: $path, contentHash: $contentHash);

        if (! $disk->exists(path: $storagePath)) {
            $this->rewind($stream);

            if ($disk->writeStream(path: $storagePath, resource: $stream) !== true) {
                throw new \RuntimeException("Не удалось сохранить representation: {$storagePath}");
            }
        }

        $stored = $disk->readStream(path: $storagePath);

        if (! is_resource($stored)) {
            throw new \RuntimeException("Object storage не вернул поток representation: {$storagePath}");
        }

        try {
            [$storedHash, $storedSize] = $this->hash($stored);
        } finally {
            fclose($stored);
        }

        if (! hash_equals($contentHash, $storedHash)) {
            throw new \RuntimeException("Object storage вернул неполный representation: {$storagePath}");
        }

        $attributes['storage_path'] = $storagePath;
        $attributes['size_bytes'] = $storedSize;

        return $this->finalize->execute(version: $version, kind: $kind, attributes: $attributes);
    }

    /** @param resource $stream */
    private function rewind(mixed $stream): void
    {
        if (rewind($stream) !== true) {
            throw new \RuntimeException('Representation stream must be seekable.');
        }
    }

    /**
     * @param  resource  $stream
     * @return array{string, int}
     */
    private function hash(mixed $stream): array
    {
        $context = hash_init('sha256');
        $size = hash_update_stream($context, $stream);

        return [hash_final($context), $size];
    }

    private function contentAddressedPath(string $path, string $contentHash): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        if ($extension === '') {
            return "{$path}.{$contentHash}";
        }

        $stem = substr($path, 0, -strlen($extension) - 1);

        return "{$stem}.{$contentHash}.{$extension}";
    }
}
