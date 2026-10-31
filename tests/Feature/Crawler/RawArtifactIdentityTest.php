<?php

declare(strict_types=1);

use App\Actions\Procurement\Crawler\StoreRawFetchArtifactAction;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Models\Procurement\Crawler\Fetch;
use App\Models\Procurement\Crawler\RawArtifact;
use Illuminate\Support\Facades\Storage;

test('distinct CSV request windows retain their own immutable bytes', function (): void {
    Storage::fake('s3');
    $store = app(StoreRawFetchArtifactAction::class);
    $one = $store->execute('s3', 'raw/csv/process', ['from' => 1, 'priceFrom' => 100], 'day one', 'csv');
    $two = $store->execute('s3', 'raw/csv/process', ['from' => 1, 'priceFrom' => 200], 'day two', 'csv');
    expect($one)->not->toBe($two)
        ->and(RawArtifact::query()->whereIn('path', [$one, $two])->count())->toBe(2)
        ->and(Storage::disk('s3')->get($one))->toBe('day one')
        ->and(Storage::disk('s3')->get($two))->toBe('day two')
        ->and(Fetch::query()->count())->toBe(0);
});

test('identical CSV replay reuses its immutable object reference', function (): void {
    Storage::fake('s3');
    $store = app(StoreRawFetchArtifactAction::class);
    $one = $store->execute('s3', 'raw/csv/process', ['from' => 1, 'priceFrom' => 100], 'same bytes', 'csv');
    $two = $store->execute('s3', 'raw/csv/process', ['priceFrom' => 100, 'from' => 1], 'same bytes', 'csv');
    expect($one)->toBe($two)->and(RawArtifact::query()->where('path', $one)->count())->toBe(1)
        ->and(Storage::disk('s3')->get($two))->toBe('same bytes');
});

test('failed checked write does not create a fetch claiming an artifact', function (): void {
    $disk = Mockery::mock();
    $disk->shouldReceive('exists')->once()->andReturnFalse();
    $disk->shouldReceive('put')->once()->andReturnFalse();
    Storage::shouldReceive('disk')->once()->with('s3')->andReturn($disk);
    expect(fn () => app(StoreRawFetchArtifactAction::class)->execute(
        's3', 'raw/csv/process', ['from' => 1], 'cannot persist', 'csv'))
        ->toThrow(DependencyUnavailable::class)
        ->and(Fetch::query()->count())->toBe(0);
});

test('raw artifact action does not expose request values in its path', function (): void {
    Storage::fake('s3');
    $path = app(StoreRawFetchArtifactAction::class)->execute(
        's3', 'raw/csv/process', ['query' => 'token=secret-value'], 'body', 'csv');
    expect($path)->not->toContain('secret-value');
});
