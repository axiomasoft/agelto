<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use App\Actions\Procurement\Document\FinalizeRepresentationAction;
use App\Enums\Procurement\Document\RepresentationKind;
use App\Models\Procurement\Document\DocumentRepresentation;
use App\Models\Procurement\Document\DocumentVersion;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;
use League\Flysystem\FilesystemException;

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
        $this->cleanupOrphanedStaging(disk: $disk, path: $path);
        $stagingPath = "{$storagePath}.staging.".Str::uuid();

        try {
            $this->rewind($stream);

            if ($disk->writeStream(path: $stagingPath, resource: $stream) !== true) {
                throw new \RuntimeException("Не удалось сохранить staging representation: {$stagingPath}");
            }

            [$stagedHash] = $this->hashStored(disk: $disk, path: $stagingPath);

            if (! hash_equals($contentHash, $stagedHash)) {
                throw new \RuntimeException("Object storage вернул неполный staging representation: {$stagingPath}");
            }

            $published = $disk->exists(path: $storagePath)
                ? $this->storedMatches(disk: $disk, path: $storagePath, expectedHash: $contentHash)
                : false;

            if (! $published) {
                $staged = $disk->readStream(path: $stagingPath);

                if (! is_resource($staged)) {
                    throw new \RuntimeException("Object storage не вернул staging stream: {$stagingPath}");
                }

                try {
                    if ($disk->writeStream(path: $storagePath, resource: $staged) !== true
                        && ! $this->storedMatches(disk: $disk, path: $storagePath, expectedHash: $contentHash)) {
                        throw new \RuntimeException("Не удалось опубликовать representation: {$storagePath}");
                    }
                } finally {
                    fclose($staged);
                }
            }

            [$storedHash, $storedSize] = $this->hashStored(disk: $disk, path: $storagePath);
        } finally {
            if ($disk->exists(path: $stagingPath)) {
                $disk->delete($stagingPath);
            }
        }

        if (! hash_equals($contentHash, $storedHash)) {
            throw new \RuntimeException("Object storage вернул неполный representation: {$storagePath}");
        }

        $attributes['storage_path'] = $storagePath;
        $attributes['size_bytes'] = $storedSize;

        $representation = $this->finalize->execute(version: $version, kind: $kind, attributes: $attributes);
        if ($representation->storage_path !== $storagePath && $disk->exists(path: $storagePath)) {
            $disk->delete($storagePath);
        }

        return $representation;
    }

    /** @return array{string, int} */
    private function hashStored(Filesystem $disk, string $path): array
    {
        $stored = $disk->readStream(path: $path);

        if (! is_resource($stored)) {
            throw new \RuntimeException("Object storage не вернул поток representation: {$path}");
        }

        try {
            return $this->hash($stored);
        } finally {
            fclose($stored);
        }
    }

    private function storedMatches(Filesystem $disk, string $path, string $expectedHash): bool
    {
        try {
            [$hash] = $this->hashStored(disk: $disk, path: $path);

            return hash_equals($expectedHash, $hash);
        } catch (\Throwable) {
            return false;
        }
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

    private function cleanupOrphanedStaging(Filesystem $disk, string $path): void
    {
        $cutoff = now()->getTimestamp() - config()->integer('documents.temporary_stale_seconds');

        try {
            $candidates = $disk->files(dirname($path));
        } catch (FilesystemException $exception) {
            report($exception);

            return;
        }

        foreach ($candidates as $candidate) {
            if (! $this->isStagingPathFor(candidate: $candidate, path: $path)) {
                continue;
            }

            try {
                if ($disk->lastModified($candidate) < $cutoff) {
                    $disk->delete($candidate);
                }
            } catch (FilesystemException $exception) {
                report($exception);
            }
        }
    }

    private function isStagingPathFor(string $candidate, string $path): bool
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $stem = $extension === '' ? $path : substr($path, 0, -strlen($extension) - 1);
        $suffix = $extension === '' ? '' : '\\.'.preg_quote($extension, '~');
        $hashedPath = preg_quote($stem, '~').'\\.[a-f0-9]{64}'.$suffix;
        $legacyPath = preg_quote($path, '~');

        return preg_match("~^(?:{$hashedPath}|{$legacyPath})\\.staging\\.([0-9a-fA-F-]{36})$~D", $candidate, $matches) === 1
            && Str::isUuid($matches[1]);
    }
}
