<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$root = getenv('AGELTO_BACKUP_EXPORT_ROOT') ?: '/backup-export';
$mode = $argv[1] ?? 'export';

if ($mode === 'restore') {
    $manifestPath = "$root/objects.json";
    if (! is_file($manifestPath)) {
        throw new RuntimeException('Missing objects.json manifest');
    }
    /** @var list<array{disk: string, path: string, bytes: int, sha256: string}> $entries */
    $entries = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    foreach ($entries as $entry) {
        $diskName = (string) ($entry['disk'] ?? '');
        $path = (string) ($entry['path'] ?? '');
        if (! in_array($diskName, ['s3', 'estimates_s3', 'local'], true)
            || $path === ''
            || str_starts_with($path, '/')
            || preg_match('~(^|/)\.\.?(/|$)|[\x00-\x1f\x7f]~', $path)
        ) {
            throw new RuntimeException('Unsafe restore entry');
        }
        if ($diskName === 'local' && ! str_starts_with($path, 'llm/receipts/')) {
            throw new RuntimeException('Unsupported local disk restore path');
        }
        $sourcePath = "$root/$diskName/$path";
        if (! is_file($sourcePath) || is_link($sourcePath)) {
            throw new RuntimeException("Missing exported object: $diskName/$path");
        }
        if (filesize($sourcePath) !== ($entry['bytes'] ?? -1) || hash_file('sha256', $sourcePath) !== ($entry['sha256'] ?? '')) {
            throw new RuntimeException("Checksum mismatch for exported object: $diskName/$path");
        }
        $stream = fopen($sourcePath, 'rb');
        if (! is_resource($stream)) {
            throw new RuntimeException('Cannot open exported object stream');
        }
        try {
            if (! Storage::disk($diskName)->writeStream($path, $stream)) {
                throw new RuntimeException("Cannot restore object: $diskName/$path");
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
    printf("Restored current-state objects: %d\n", count($entries));

    return;
}

$manifest = [];
$targets = [
    ['disk' => 's3', 'directory' => null],
    ['disk' => 'estimates_s3', 'directory' => null],
    ['disk' => 'local', 'directory' => 'llm/receipts'],
];
foreach ($targets as $targetConfig) {
    $diskName = $targetConfig['disk'];
    $directory = $targetConfig['directory'];
    $disk = Storage::disk($diskName);
    $paths = $directory === null ? $disk->allFiles() : $disk->allFiles($directory);
    foreach ($paths as $path) {
        if ($path === '' || str_starts_with($path, '/') || preg_match('~(^|/)\.\.?(/|$)|[\x00-\x1f\x7f]~', $path)) {
            throw new RuntimeException('Unsafe object path');
        }
        $destination = "$root/$diskName/$path";
        if (! is_dir(dirname($destination)) && ! mkdir(dirname($destination), 0700, true)) {
            throw new RuntimeException('Cannot create export directory');
        }
        $source = $disk->readStream($path);
        $target = fopen($destination, 'xb');
        if (! is_resource($source) || ! is_resource($target)) {
            throw new RuntimeException('Cannot open object streams');
        }
        if (stream_copy_to_stream($source, $target) === false) {
            throw new RuntimeException('Cannot copy object');
        }
        fclose($source);
        fclose($target);
        chmod($destination, 0600);
        $manifest[] = ['disk' => $diskName, 'path' => $path, 'bytes' => filesize($destination), 'sha256' => hash_file('sha256', $destination)];
    }
}
file_put_contents("$root/objects.json", json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
printf("Exported current-state objects: %d\n", count($manifest));
