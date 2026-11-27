<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Tests\Support\TestFilesystemIsolation;

test('все app-owned диски переписаны в process-scoped local root', function () {
    $root = config('testing.filesystem_root');
    $disks = config('filesystems.disks');

    expect($root)
        ->toBeString()
        ->toStartWith(storage_path('framework/testing/'));

    foreach ($disks as $disk) {
        expect($disk['driver'])->toBe('local')
            ->and($disk['root'])->toStartWith($root.'/disks/');
    }
});

test('log, temp, auth и artifact roots принадлежат process-root', function () {
    $root = config('testing.process_root');

    foreach (['log_root', 'temp_root', 'auth_root', 'artifact_root'] as $name) {
        expect(config("testing.{$name}"))->toStartWith($root.'/');
    }

    expect(sys_get_temp_dir())->toBe(config('testing.temp_root'));

    Log::info('P11 process-scoped log contract');

    expect(file_get_contents(config('testing.log_root').'/laravel.log'))
        ->toContain('P11 process-scoped log contract');
});

test('teardown удаляет только named process-root', function () {
    $root = TestFilesystemIsolation::prepare(process: 'p11_owned_cleanup');
    file_put_contents($root.'/artifacts/marker.txt', 'owned');

    TestFilesystemIsolation::cleanup(process: 'p11_owned_cleanup');

    expect(is_dir($root))->toBeFalse();
});
