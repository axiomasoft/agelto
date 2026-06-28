<?php

declare(strict_types=1);

use App\Enums\Crawler\CrawlerRunStatus;
use App\Interfaces\Crawling\BrowserClient;
use App\Models\Crawler\Crawler;
use App\Models\Crawler\CrawlerRun;
use Tests\Support\Doubles\FakeCrawlingService;

test('создаёт прогон, бьёт heartbeat и штатно завершает его', function () {
    $crawler = Crawler::factory()->create();

    FakeCrawlingService::make()->start($crawler);

    $run = CrawlerRun::query()->where('crawler_id', $crawler->id)->firstOrFail();

    expect($run->status)->toBe(CrawlerRunStatus::Completed)
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->last_heartbeat_at)->not->toBeNull();

    expect($run)->toHaveLoggedMessage('%Старт прогона%');
    expect($run)->toHaveLoggedMessage('%завершён%');
});

test('фиксирует падение прогона и сохраняет страницу курсора', function () {
    $crawler = Crawler::factory()->create();

    expect(fn () => FakeCrawlingService::make()->throwing()->start($crawler))
        ->toThrow(RuntimeException::class);

    $run = CrawlerRun::query()->where('crawler_id', $crawler->id)->firstOrFail();

    expect($run->status)->toBe(CrawlerRunStatus::Failed)
        ->and($run->last_error)->toBe('boom')
        ->and($run->attempts)->toBe(1)
        ->and($run->cursor_url)->not->toBeNull() // heartbeat записал страницу до падения
        ->and($run->current_page)->toBe(1);

    expect($run)->toHaveLoggedMessage('%упал%');
});

test('не запускает второй прогон, если по профилю уже идёт живой', function () {
    $crawler = Crawler::factory()->create();

    CrawlerRun::factory()->for($crawler)->create();

    // Ранний выход — браузер не трогаем: строгий мок это докажет.
    $browser = Mockery::mock(BrowserClient::class);
    $browser->shouldNotReceive('withRandomProxy');

    FakeCrawlingService::make($browser)->start($crawler);

    expect(CrawlerRun::query()->where('crawler_id', $crawler->id)->count())->toBe(1);
});
