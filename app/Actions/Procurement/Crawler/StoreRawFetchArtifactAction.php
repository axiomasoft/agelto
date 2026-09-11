<?php

declare(strict_types=1);

namespace App\Actions\Procurement\Crawler;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

/** Stores one content-addressed raw fetch object without allowing an overwrite. */
final readonly class StoreRawFetchArtifactAction
{
    /**
     * @param  array<string, mixed>  $requestIdentity
     */
    public function execute(string $disk, string $prefix, array $requestIdentity, string $contents, string $extension): string
    {
        $identityHash = hash('sha256', $this->canonicalJson($requestIdentity));
        $contentHash = hash('sha256', $contents);
        $path = "{$prefix}/{$identityHash}/{$contentHash}.{$extension}";
        $storage = Storage::disk($disk);

        if ($storage->exists($path)) {
            $this->verifyStoredContents(storage: $storage, path: $path, contentHash: $contentHash);

            return $path;
        }

        if ($storage->put($path, $contents) !== true) {
            throw new \RuntimeException("Unable to persist raw fetch artifact at [{$path}].");
        }

        $this->verifyStoredContents(storage: $storage, path: $path, contentHash: $contentHash);

        return $path;
    }

    /** @param array<string, mixed> $value */
    private function canonicalJson(array $value): string
    {
        return json_encode($this->sortRecursively($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<mixed> $value
     *  @return array<mixed> */
    private function sortRecursively(array $value): array
    {
        foreach ($value as $key => $nested) {
            if (is_array($nested)) {
                $value[$key] = $this->sortRecursively($nested);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function verifyStoredContents(FilesystemAdapter $storage, string $path, string $contentHash): void
    {
        if (! $storage->exists($path)) {
            throw new \RuntimeException("Raw fetch artifact verification failed at [{$path}].");
        }

        $contents = $storage->get($path);

        if (! is_string($contents) || hash('sha256', $contents) !== $contentHash) {
            throw new \RuntimeException("Raw fetch artifact verification failed at [{$path}].");
        }
    }
}
