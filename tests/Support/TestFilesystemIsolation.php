<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\ParallelTesting;
use RuntimeException;

final class TestFilesystemIsolation
{
    public static function configure(Application $app): void
    {
        $token = ParallelTesting::token();
        $process = is_string($token) ? self::normalizeToken(token: $token) : 'serial';
        $root = self::prepare(process: $process);

        $realRoot = realpath($root);
        $realBase = realpath(storage_path('framework/testing'));

        if ($realRoot === false || $realBase === false || ! str_starts_with($realRoot.'/', $realBase.'/')) {
            throw new RuntimeException('ИЗОЛЯЦИЯ ТЕСТОВ НАРУШЕНА: testing-root выходит за storage/framework/testing.');
        }

        $config = $app->make('config');
        $disks = $config->get('filesystems.disks');

        if (! is_array($disks)) {
            throw new RuntimeException('ИЗОЛЯЦИЯ ТЕСТОВ НАРУШЕНА: filesystems.disks должен быть массивом.');
        }

        foreach ($disks as $name => $disk) {
            if (! is_string($name) || ! is_array($disk)) {
                throw new RuntimeException('ИЗОЛЯЦИЯ ТЕСТОВ НАРУШЕНА: каждый filesystem disk должен иметь строковое имя и массив config.');
            }

            $config->set("filesystems.disks.{$name}", [
                ...$disk,
                'driver' => 'local',
                'root' => "{$realRoot}/disks/{$name}",
                'throw' => true,
            ]);
        }

        $logs = "{$realRoot}/logs";
        $temp = "{$realRoot}/tmp";
        $auth = "{$realRoot}/auth";
        $artifacts = "{$realRoot}/artifacts";

        putenv("TMPDIR={$temp}");
        putenv("TMP={$temp}");
        putenv("TEMP={$temp}");
        $_SERVER['TMPDIR'] = $temp;
        $_ENV['TMPDIR'] = $temp;

        $config->set('filesystems.default', 'local');
        $config->set('testing.filesystem_root', $realRoot);
        $config->set('testing.process_root', $realRoot);
        $config->set('testing.log_root', $logs);
        $config->set('testing.temp_root', $temp);
        $config->set('testing.auth_root', $auth);
        $config->set('testing.artifact_root', $artifacts);
        $config->set('logging.channels.single.path', "{$logs}/laravel.log");
        $config->set('logging.channels.daily.path', "{$logs}/laravel.log");
        $config->set('logging.channels.json_file.handler_with.stream', "{$logs}/laravel-json.log");
        $config->set('logging.channels.emergency.path', "{$logs}/laravel.log");
    }

    public static function prepare(string $process): string
    {
        $root = storage_path('framework/testing/'.self::normalizeToken(token: $process));

        if (! is_dir($root) && ! mkdir(directory: $root, permissions: 0755, recursive: true) && ! is_dir($root)) {
            throw new RuntimeException("Не удалось создать изолированный testing-root: {$root}");
        }

        foreach (['disks', 'logs', 'tmp', 'auth', 'artifacts'] as $directory) {
            $path = "{$root}/{$directory}";

            if (! is_dir($path) && ! mkdir(directory: $path, permissions: 0755, recursive: true) && ! is_dir($path)) {
                throw new RuntimeException("Не удалось создать изолированный testing-root: {$path}");
            }
        }

        return $root;
    }

    public static function cleanup(string $process): void
    {
        $base = dirname(__DIR__, 2).'/storage/framework/testing';
        $root = $base.'/'.self::normalizeToken(token: $process);
        $realRoot = realpath($root);
        $realBase = realpath($base);

        if ($realRoot === false || $realBase === false || ! str_starts_with($realRoot.'/', $realBase.'/')) {
            throw new RuntimeException('ИЗОЛЯЦИЯ ТЕСТОВ НАРУШЕНА: teardown отказался удалить не-own process-root.');
        }

        self::deleteOwnedDirectory(directory: $realRoot);
    }

    private static function deleteOwnedDirectory(string $directory): void
    {
        $entries = scandir($directory);

        if ($entries === false) {
            throw new RuntimeException("Не удалось прочитать testing-root для cleanup: {$directory}");
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = "{$directory}/{$entry}";

            if (is_link($path) || is_file($path)) {
                if (! unlink($path)) {
                    throw new RuntimeException("Не удалось удалить testing-файл: {$path}");
                }

                continue;
            }

            if (is_dir($path)) {
                self::deleteOwnedDirectory(directory: $path);

                continue;
            }

            throw new RuntimeException("ИЗОЛЯЦИЯ ТЕСТОВ НАРУШЕНА: неизвестный объект в testing-root: {$path}");
        }

        if (! rmdir($directory)) {
            throw new RuntimeException("Не удалось удалить testing-root: {$directory}");
        }
    }

    private static function normalizeToken(string $token): string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $token) !== 1) {
            throw new RuntimeException('ИЗОЛЯЦИЯ ТЕСТОВ НАРУШЕНА: Laravel parallel token содержит недопустимые символы.');
        }

        return $token;
    }
}
