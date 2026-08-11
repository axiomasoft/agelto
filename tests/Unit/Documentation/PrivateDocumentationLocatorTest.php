<?php

declare(strict_types=1);

use App\Services\Documentation\PrivateDocumentationLocator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function (): void {
    $this->documentationRoot = sys_get_temp_dir().'/agelto-documentation-'.bin2hex(random_bytes(8));
    mkdir($this->documentationRoot.'/site', recursive: true);
    config(['documentation.root' => $this->documentationRoot]);
});

afterEach(function (): void {
    documentationRemoveDirectory($this->documentationRoot);
});

test('находит root nested и static manifest entries', function (): void {
    documentationWriteArtifact($this->documentationRoot, [
        'assets/app.css' => ['text/css; charset=utf-8', 'body { color: black; }'],
        'guide/index.html' => ['text/html; charset=utf-8', '<h1>nested sentinel</h1>'],
        'index.html' => ['text/html; charset=utf-8', '<h1>root sentinel</h1>'],
    ]);

    $locator = new PrivateDocumentationLocator;

    expect($locator->locate(requestedPath: null)->path)->toBe('index.html')
        ->and($locator->locate(requestedPath: 'guide/index.html')->mime)->toBe('text/html; charset=utf-8')
        ->and($locator->locate(requestedPath: 'assets/app.css')->mime)->toBe('text/css; charset=utf-8');
});

test('отклоняет одинаковой 404-поверхностью небезопасные пути', function (string $path): void {
    documentationWriteArtifact($this->documentationRoot, [
        'index.html' => ['text/html; charset=utf-8', 'root sentinel'],
    ]);

    expect(fn () => (new PrivateDocumentationLocator)->locate(requestedPath: $path))
        ->toThrow(NotFoundHttpException::class);
})->with([
    'encoded traversal' => '%2e%2e/index.html',
    'double encoded traversal' => '%252e%252e/index.html',
    'backslash traversal' => '..\\index.html',
    'nul byte' => "index.html\0.css",
    'dot segment' => './index.html',
    'parent segment' => '../index.html',
    'empty segment' => 'guide//index.html',
    'unknown extension' => 'index.exe',
]);

test('отклоняет missing build malformed manifest missing file и symlink escape', function (): void {
    documentationRemoveDirectory($this->documentationRoot);

    expect(fn () => (new PrivateDocumentationLocator)->locate(requestedPath: null))
        ->toThrow(NotFoundHttpException::class);

    mkdir($this->documentationRoot.'/site', recursive: true);
    file_put_contents($this->documentationRoot.'/manifest.json', '{not-json');

    expect(fn () => (new PrivateDocumentationLocator)->locate(requestedPath: null))
        ->toThrow(NotFoundHttpException::class);

    documentationWriteArtifact($this->documentationRoot, [
        'index.html' => ['text/html; charset=utf-8', 'root sentinel'],
    ]);
    unlink($this->documentationRoot.'/site/index.html');

    expect(fn () => (new PrivateDocumentationLocator)->locate(requestedPath: null))
        ->toThrow(NotFoundHttpException::class);

    file_put_contents($this->documentationRoot.'/outside.html', 'outside sentinel');
    symlink($this->documentationRoot.'/outside.html', $this->documentationRoot.'/site/index.html');

    expect(fn () => (new PrivateDocumentationLocator)->locate(requestedPath: null))
        ->toThrow(NotFoundHttpException::class);
});

test('отклоняет unsorted duplicate и unknown-mime manifest entries', function (): void {
    file_put_contents($this->documentationRoot.'/site/index.html', 'root sentinel');
    file_put_contents($this->documentationRoot.'/site/a.html', 'a sentinel');

    file_put_contents($this->documentationRoot.'/manifest.json', json_encode([
        ['path' => 'index.html', 'mime' => 'text/html; charset=utf-8', 'sha256' => str_repeat('a', 64)],
        ['path' => 'a.html', 'mime' => 'text/html; charset=utf-8', 'sha256' => str_repeat('b', 64)],
    ], flags: JSON_PRETTY_PRINT));

    expect(fn () => (new PrivateDocumentationLocator)->locate(requestedPath: null))
        ->toThrow(NotFoundHttpException::class);

    file_put_contents($this->documentationRoot.'/manifest.json', json_encode([
        ['path' => 'index.html', 'mime' => 'application/octet-stream', 'sha256' => str_repeat('a', 64)],
    ], flags: JSON_PRETTY_PRINT));

    expect(fn () => (new PrivateDocumentationLocator)->locate(requestedPath: null))
        ->toThrow(NotFoundHttpException::class);
});

test('отклоняет active svg даже при forged manifest mime', function (): void {
    file_put_contents($this->documentationRoot.'/site/active.svg', '<svg><script>alert(document.domain)</script></svg>');
    file_put_contents($this->documentationRoot.'/manifest.json', json_encode([
        ['path' => 'active.svg', 'mime' => 'image/svg+xml', 'sha256' => hash_file('sha256', $this->documentationRoot.'/site/active.svg')],
    ], flags: JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    expect(fn () => (new PrivateDocumentationLocator)->locate(requestedPath: 'active.svg'))
        ->toThrow(NotFoundHttpException::class);
});

/**
 * @param  array<string, array{0: string, 1: string}>  $files
 */
function documentationWriteArtifact(string $root, array $files): void
{
    $manifest = [];

    ksort($files, SORT_STRING);

    foreach ($files as $path => [$mime, $contents]) {
        $absolutePath = $root.'/site/'.$path;
        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), recursive: true);
        }

        file_put_contents($absolutePath, $contents);
        $manifest[] = [
            'path' => $path,
            'mime' => $mime,
            'sha256' => hash('sha256', $contents),
        ];
    }

    file_put_contents($root.'/manifest.json', json_encode($manifest, flags: JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
}

function documentationRemoveDirectory(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($entries as $entry) {
        $entry->isDir() && ! $entry->isLink()
            ? rmdir($entry->getPathname())
            : unlink($entry->getPathname());
    }

    rmdir($directory);
}
