<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use RuntimeException;

final class PromptWorkspace
{
    public static function refresh(string $source, string $target): void
    {
        if (is_link($source) || is_link($target) || ! is_dir($source) || ! is_dir($target)
            || realpath($source) === realpath($target)) {
            throw new RuntimeException('Prompt workspace requires distinct real source and owned target directories.');
        }

        self::clear(directory: $target);
        self::copy(source: $source, target: $target);
    }

    private static function clear(string $directory): void
    {
        foreach (new \FilesystemIterator($directory) as $file) {
            if ($file->isLink()) {
                throw new RuntimeException('Prompt workspace refuses symlinks.');
            }

            if ($file->isDir()) {
                self::clear(directory: $file->getPathname());
                $removed = rmdir($file->getPathname());
            } else {
                $removed = unlink($file->getPathname());
            }

            if (! $removed) {
                throw new RuntimeException('Cannot clear the owned prompt workspace.');
            }
        }
    }

    private static function copy(string $source, string $target): void
    {
        foreach (new \FilesystemIterator($source) as $file) {
            if ($file->isLink()) {
                throw new RuntimeException('Prompt workspace refuses symlinks.');
            }

            $destination = $target.'/'.$file->getFilename();

            if ($file->isDir()) {
                if (! mkdir($destination)) {
                    throw new RuntimeException('Cannot create an owned prompt directory.');
                }

                self::copy(source: $file->getPathname(), target: $destination);
            } elseif (! copy($file->getPathname(), $destination)) {
                throw new RuntimeException('Cannot copy the readonly prompt input.');
            }
        }
    }
}
