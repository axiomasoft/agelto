<?php

declare(strict_types=1);

namespace App\Services\Documentation;

use Illuminate\Support\Facades\Cache;
use JsonException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class PrivateDocumentationLocator
{
    public function locate(?string $requestedPath): PrivateDocumentationAsset
    {
        $root = $this->root();
        $site = $this->site($root);
        $manifest = $this->manifest($root);
        $path = $this->resolveRequestPath(requestedPath: $requestedPath, manifest: $manifest);
        $entry = $manifest[$path];

        $absolutePath = realpath($site.DIRECTORY_SEPARATOR.$path);

        if ($absolutePath === false || ! is_file($absolutePath) || ! $this->isContainedBy($absolutePath, $site)) {
            $this->notFound();
        }

        return new PrivateDocumentationAsset(
            path: $path,
            absolutePath: $absolutePath,
            mime: $entry['mime'],
            securityHeaders: $this->securityHeaders(),
            htmlCsp: $this->configString('documentation.html_csp'),
        );
    }

    private function root(): string
    {
        $root = realpath($this->configString('documentation.root'));

        if ($root === false || ! is_dir($root)) {
            $this->notFound();
        }

        return $root;
    }

    private function site(string $root): string
    {
        $site = realpath($root.DIRECTORY_SEPARATOR.$this->configString('documentation.site_directory'));

        if ($site === false || ! is_dir($site) || ! $this->isContainedBy($site, $root)) {
            $this->notFound();
        }

        return $site;
    }

    /**
     * @param  array<string, array{mime: string}>  $manifest
     */
    private function resolveRequestPath(?string $requestedPath, array $manifest): string
    {
        $requestedPath ??= '';
        $candidates = match (true) {
            $requestedPath === '' => ['index.html'],
            str_ends_with($requestedPath, '/') => ["{$requestedPath}index.html"],
            pathinfo($requestedPath, PATHINFO_EXTENSION) === '' => ["{$requestedPath}/index.html", "{$requestedPath}.html"],
            default => [$requestedPath],
        };

        foreach ($candidates as $path) {
            if (! $this->isSafePath($path)) {
                $this->notFound();
            }

            if (isset($manifest[$path])) {
                return $path;
            }
        }

        $this->notFound();
    }

    /**
     * @return array<string, array{mime: string}>
     */
    private function manifest(string $root): array
    {
        $manifestPath = realpath($root.DIRECTORY_SEPARATOR.$this->configString('documentation.manifest'));

        if ($manifestPath === false || ! is_file($manifestPath) || ! $this->isContainedBy($manifestPath, $root)) {
            $this->notFound();
        }

        clearstatcache(filename: $manifestPath);
        $mtime = @filemtime($manifestPath);
        $size = @filesize($manifestPath);
        $mimeTypes = config('documentation.mime_types');

        if ($mtime === false || $size === false) {
            $this->notFound();
        }

        $cacheKey = 'documentation.manifest.v1.'.hash('sha256', serialize([$manifestPath, $mtime, $size, $mimeTypes]));

        /** @var array<string, array{mime: string}> $entries */
        $entries = Cache::remember(key: $cacheKey, ttl: 300, callback: fn (): array => $this->readManifest($manifestPath));

        return $entries;
    }

    /** @return array<string, array{mime: string}> */
    private function readManifest(string $manifestPath): array
    {
        $contents = @file_get_contents($manifestPath);
        if ($contents === false) {
            $this->notFound();
        }

        try {
            $entries = json_decode($contents, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->notFound();
        }

        if (! is_array($entries) || ! array_is_list($entries) || $entries === []) {
            $this->notFound();
        }

        $manifest = [];
        $previousPath = null;

        foreach ($entries as $entry) {
            if (! is_array($entry) || array_keys($entry) !== ['path', 'mime', 'sha256']) {
                $this->notFound();
            }

            $path = $entry['path'] ?? null;
            $mime = $entry['mime'] ?? null;
            $sha256 = $entry['sha256'] ?? null;

            if (! is_string($path) || ! is_string($mime) || ! is_string($sha256) || ! $this->isSafePath($path)) {
                $this->notFound();
            }

            if ($previousPath !== null && strcmp($previousPath, $path) >= 0) {
                $this->notFound();
            }

            if (! preg_match('/\A[a-f0-9]{64}\z/D', $sha256) || $this->mimeFor($path) !== $mime) {
                $this->notFound();
            }

            $manifest[$path] = ['mime' => $mime];
            $previousPath = $path;
        }

        return $manifest;
    }

    private function isSafePath(string $path): bool
    {
        if ($path === '' || str_contains($path, '%') || str_contains($path, '\\') || str_contains($path, "\0")) {
            return false;
        }

        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9.@_\/-]*\z/D', $path) !== 1) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return $this->mimeFor($path) !== null;
    }

    private function mimeFor(string $path): ?string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        if ($extension === '') {
            return null;
        }

        $mimeTypes = config('documentation.mime_types');

        if (! is_array($mimeTypes)) {
            return null;
        }

        $mime = $mimeTypes[strtolower(".{$extension}")] ?? null;

        return is_string($mime) ? $mime : null;
    }

    /**
     * @return array<string, string>
     */
    private function securityHeaders(): array
    {
        $headers = config('documentation.security_headers');

        if (! is_array($headers)) {
            $this->notFound();
        }

        $result = [];

        foreach ($headers as $name => $value) {
            if (! is_string($name) || ! is_string($value)) {
                $this->notFound();
            }

            $result[$name] = $value;
        }

        return $result;
    }

    private function isContainedBy(string $path, string $root): bool
    {
        return str_starts_with($path, $root.DIRECTORY_SEPARATOR);
    }

    private function notFound(): never
    {
        throw new NotFoundHttpException;
    }

    private function configString(string $key): string
    {
        $value = config($key);

        if (! is_string($value)) {
            $this->notFound();
        }

        return $value;
    }
}
