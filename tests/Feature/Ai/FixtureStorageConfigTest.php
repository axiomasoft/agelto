<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

test('оба диска эталонов резолвятся конфигом', function (): void {
    Storage::fake(config('ai.fixtures.disk'));

    expect(Storage::disk(config('ai.fixtures.disk')))->toBeInstanceOf(Filesystem::class)
        ->and(Storage::disk('estimates_s3'))->toBeInstanceOf(Filesystem::class);
});

test('ключи ai.fixtures непусты', function (): void {
    expect(config('ai.fixtures.disk'))->not->toBeEmpty()
        ->and(config('ai.fixtures.manifest_path'))->not->toBeEmpty()
        ->and(config('ai.fixtures.gold_dir'))->not->toBeEmpty()
        ->and(config('ai.fixtures.source_dir'))->not->toBeEmpty();
});

test('дефолтный диск эталонов в тестовом окружении не s3', function (): void {
    expect(config('ai.fixtures.disk'))->not->toBe('s3')
        ->and(config('ai.fixtures.disk'))->toBe('estimates');
});
