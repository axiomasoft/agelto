<?php

declare(strict_types=1);

use App\Actions\Procurement\Crawler\StoreRawFetchArtifactAction;
use App\Dto\Procurement\Crawler\ListingBatch;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Fetch;
use App\Models\Procurement\Crawler\RawArtifact;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\CsvBlockIngestor;
use Illuminate\Support\Facades\Storage;

function immutableArtifactBatch(string $body, string $from, string $priceFrom): ListingBatch
{
    return new ListingBatch(
        rows: [],
        rawBody: $body,
        dedupSignature: null,
        rowsTotal: 0,
        httpStatus: 200,
        durationMs: 1,
        requestParams: ['from' => $from, 'priceFrom' => $priceFrom, 'to' => '500'],
    );
}

test('distinct CSV request windows retain their own immutable bytes', function () {
    Storage::fake('s3');
    $registry = Registry::factory()->eis()->create();
    $crawler = Crawler::factory()->for($registry)->create();
    $run = Run::factory()->for($crawler)->create();

    app(CsvBlockIngestor::class)->ingest(
        crawler: $crawler,
        run: $run,
        batch: immutableArtifactBatch(body: 'day one', from: '1', priceFrom: '100'),
    );
    app(CsvBlockIngestor::class)->ingest(
        crawler: $crawler,
        run: $run,
        batch: immutableArtifactBatch(body: 'day two', from: '1', priceFrom: '200'),
    );

    $paths = RawArtifact::query()->where('path', 'like', "raw/csv/{$crawler->id}/{$run->id}/%")
        ->pluck('path')->all();
    $contents = array_map(fn ($path) => Storage::disk('s3')->get($path), $paths);
    sort($contents);
    expect($paths)->toHaveCount(2)->and(array_unique($paths))->toHaveCount(2)
        ->and($contents)->toBe(['day one', 'day two'])
        ->and(Fetch::query()->where('crawler_run_id', $run->id)->count())->toBe(0);
});

test('identical CSV replay reuses its immutable object reference', function () {
    Storage::fake('s3');
    $registry = Registry::factory()->eis()->create();
    $crawler = Crawler::factory()->for($registry)->create();
    $run = Run::factory()->for($crawler)->create();
    $batch = immutableArtifactBatch(body: 'same bytes', from: '1', priceFrom: '100');

    app(CsvBlockIngestor::class)->ingest(crawler: $crawler, run: $run, batch: $batch);
    app(CsvBlockIngestor::class)->ingest(crawler: $crawler, run: $run, batch: $batch);

    expect(RawArtifact::query()->where('path', 'like', "raw/csv/{$crawler->id}/{$run->id}/%")->pluck('path')->all())
        ->toHaveCount(1);
});

test('failed checked write does not create a fetch claiming an artifact', function () {
    $disk = Mockery::mock();
    $disk->shouldReceive('exists')->once()->andReturnFalse();
    $disk->shouldReceive('put')->once()->andReturnFalse();
    Storage::shouldReceive('disk')->once()->with('s3')->andReturn($disk);

    $registry = Registry::factory()->eis()->create();
    $crawler = Crawler::factory()->for($registry)->create();
    $run = Run::factory()->for($crawler)->create();

    expect(fn () => app(CsvBlockIngestor::class)->ingest(
        crawler: $crawler,
        run: $run,
        batch: immutableArtifactBatch(body: 'cannot persist', from: '1', priceFrom: '100'),
    ))->toThrow(RuntimeException::class);

    expect(Fetch::query()->where('crawler_run_id', $run->id)->count())->toBe(0);
});

test('raw artifact action does not expose request values in its path', function () {
    Storage::fake('s3');

    $path = app(StoreRawFetchArtifactAction::class)->execute(
        disk: 's3',
        prefix: 'raw/csv/1/1',
        requestIdentity: ['query' => 'token=secret-value'],
        contents: 'body',
        extension: 'csv',
    );

    expect($path)->not->toContain('secret-value');
});
