<?php

declare(strict_types=1);

namespace App\Support\Files;

use RuntimeException;
use SplFileInfo;
use Symfony\Component\Filesystem\Filesystem;

final class PipelineTemporaryFile
{
    private const string Directory = 'agelto-pipeline';

    public static function create(string $prefix, string $extension): string
    {
        $directory = self::directory();

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create pipeline temporary directory: {$directory}");
        }

        $extension = ltrim($extension, '.');

        if ($extension === '' || preg_match('/^[a-z0-9]+$/i', $extension) !== 1) {
            throw new RuntimeException('Temporary file extension is invalid.');
        }

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $path = $directory.'/'.$prefix.bin2hex(random_bytes(16)).'.'.$extension;
            $handle = @fopen($path, 'x+b');

            if (! is_resource($handle)) {
                continue;
            }

            fclose($handle);
            chmod($path, 0600);

            return $path;
        }

        throw new RuntimeException('Cannot allocate a unique pipeline temporary file.');
    }

    public static function createDirectory(string $prefix): string
    {
        $root = self::directory();

        if (! is_dir($root) && ! mkdir($root, 0700, true) && ! is_dir($root)) {
            throw new RuntimeException("Cannot create pipeline temporary directory: {$root}");
        }

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $path = $root.'/'.$prefix.bin2hex(random_bytes(16));

            if (@mkdir($path, 0700)) {
                return $path;
            }
        }

        throw new RuntimeException('Cannot allocate a unique pipeline temporary workspace.');
    }

    public static function deleteDirectory(string $path): void
    {
        if (! str_starts_with($path, self::directory().'/')) {
            throw new RuntimeException('Refusing to delete a directory outside the pipeline temporary root.');
        }

        (new Filesystem)->remove($path);
    }

    public static function purgeOlderThan(int $seconds): int
    {
        if ($seconds < 1) {
            throw new \InvalidArgumentException('Temporary file retention must be positive.');
        }

        $directory = self::directory();

        if (! is_dir($directory)) {
            return 0;
        }

        $removed = 0;
        $cutoff = time() - $seconds;

        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $file) {
            if (! $file instanceof SplFileInfo || $file->getMTime() >= $cutoff) {
                continue;
            }

            if ($file->isDir()) {
                (new Filesystem)->remove($file->getPathname());
                $removed++;

                continue;
            }

            if ($file->isFile() && @unlink($file->getPathname())) {
                $removed++;
            }
        }

        return $removed;
    }

    private static function directory(): string
    {
        return rtrim(sys_get_temp_dir(), '/').'/'.self::Directory;
    }
}
