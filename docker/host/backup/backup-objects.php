<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$root = '/backup-export';
$manifest = [];
foreach (['s3', 'estimates_s3'] as $diskName) {
    $disk = Storage::disk($diskName);
    foreach ($disk->allFiles() as $path) {
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
