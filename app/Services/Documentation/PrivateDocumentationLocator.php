<?php

declare(strict_types=1);

namespace App\Services\Documentation;

use JsonException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class PrivateDocumentationLocator
{
    public function locate(?string $requestedPath): PrivateDocumentationAsset
    {
        $root = $this->root();
        $site = $this->site($root);
        $path = $this->normalizeRequestPath($requestedPath);
        $entry = $this->manifest($root)[$path] ?? null;

        if ($entry === null) {
            $this->notFound();
        }

        $absolutePath = realpath($site.DIRECTORY_SEPARATOR.$path);

        if ($absolutePath === false || ! is_file($absolutePath) || ! $this->isContainedBy($absolutePath, $site)) {
            $this->notFound();
        }

        return new PrivateDocumentationAsset(
            path: $path,
            absolutePath: $absolutePath,
            mime: $entry['mime'],
            securityHeaders: $this->securityHeaders(),
            htmlCsp: (string) config('documentation.html_csp'),
        );
    }

    private function root(): string
    {
        $root = realpath((string) config('documentation.root'));

        if ($root === false || ! is_dir($root)) {
            $this->notFound();
        }

        return $root;
    }

    private function site(string $root): string
    {
        $site = realpath($root.DIRECTORY_SEPARATOR.(string) config('documentation.site_directory'));

        if ($site === false || ! is_dir($site) || ! $this->isContainedBy($site, $root)) {
            $this->notFound();
        }

        return $site;
    }

    private function normalizeRequestPath(?string $requestedPath): string
    {
        $path = $requestedPath === null || $requestedPath === ''
            ? 'index.html'
            : $requestedPath;

        if (! $this->isSafePath($path)) {
            $this->notFound();
        }

        return $path;
    }

    /**
     * @return array<string, array{mime: string}>
     */
    private function manifest(string $root): array
    {
        $manifestPath = realpath($root.DIRECTORY_SEPARATOR.(string) config('documentation.manifest'));

        if ($manifestPath === false || ! is_file($manifestPath) || ! $this->isContainedBy($manifestPath, $root)) {
            $this->notFound();
        }

        try {
            $entries = json_decode((string) file_get_contents($manifestPath), associative: true, flags: JSON_THROW_ON_ERROR);
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

        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._\/-]*\z/D', $path) !== 1) {
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
        return array_map(strval(...), (array) config('documentation.security_headers'));
    }

    private function isContainedBy(string $path, string $root): bool
    {
        return str_starts_with($path, $root.DIRECTORY_SEPARATOR);
    }

    private function notFound(): never
    {
        throw new NotFoundHttpException;
    }
}
