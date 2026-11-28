<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\TestEnvironmentGuard;

pest()->group('document-storage-and-references');

test('изолированный Silo сохраняет, читает и удаляет объект через S3', function (): void {
    TestEnvironmentGuard::assertBootstrapTestDatabase(app());
    $config = config('filesystems.disks.s3');
    if (getenv('PROCESS_CORE_TEST') !== '1' || ! is_array($config)
        || ($config['endpoint'] ?? null) !== 'http://object-storage:9000'
        || ($config['bucket'] ?? null) !== 'core-test') {
        throw new LogicException('Silo check requires the isolated core-test bucket.');
    }

    $disk = Storage::build([...$config, 'throw' => true]);
    $key = 'rig-checks/'.Str::uuid().'.txt';
    $contents = 'isolated Silo storage check';

    try {
        expect($disk->put($key, $contents))->toBeTrue()
            ->and($disk->get($key))->toBe($contents);
    } finally {
        $disk->delete($key);
    }
    expect($disk->exists($key))->toBeFalse();
});
