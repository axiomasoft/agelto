<?php

declare(strict_types=1);

use App\Enums\Crawler\CrawlerRunStatus;
use App\Models\Crawler\Crawler;
use App\Models\Crawler\CrawlerRun;
use App\Process\Crawling\Run\CrawlerRunManager;

beforeEach(function () {
    $this->manager = app(CrawlerRunManager::class);
});

test('создаёт активный прогон и пишет веху старта в журнал', function () {
    $crawler = Crawler::factory()->create();

    $run = $this->manager->startOrResume($crawler);

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(CrawlerRunStatus::Running)
        ->and($run->started_at)->not->toBeNull()
        ->and($run->last_heartbeat_at)->not->toBeNull();

    expect($run)->toHaveLoggedMessage('%Старт прогона%');
});

test('не создаёт второй прогон, пока активный жив (свежий heartbeat)', function () {
    $crawler = Crawler::factory()->create();

    $first = $this->manager->startOrResume($crawler);
    $second = $this->manager->startOrResume($crawler);

    expect($second)->toBeNull()
        ->and(CrawlerRun::query()->where('crawler_id', $crawler->id)->count())->toBe(1)
        ->and($first->fresh()->status)->toBe(CrawlerRunStatus::Running);
});

test('возобновляет оборванный прогон (устаревший heartbeat), сохраняя курсор', function () {
    $crawler = Crawler::factory()->create();

    $orphan = CrawlerRun::factory()->for($crawler)->orphaned()->create([
        'day_from' => '2025-03-04',
        'current_page' => 7,
    ]);

    $resumed = $this->manager->startOrResume($crawler);

    expect($resumed->id)->toBe($orphan->id)
        ->and($resumed->status)->toBe(CrawlerRunStatus::Running)
        ->and($resumed->attempts)->toBe(1)
        ->and($resumed->current_page)->toBe(7)
        ->and($resumed->day_from->toDateString())->toBe('2025-03-04')
        ->and(CrawlerRun::query()->where('crawler_id', $crawler->id)->count())->toBe(1);

    expect($orphan)->toHaveLoggedMessage('%оборванный прогон%');
});

test('двигает heartbeat и чекпойнт курсора', function () {
    $crawler = Crawler::factory()->create();
    $run = $this->manager->startOrResume($crawler);

    $this->manager->heartbeat($run, ['cursor_url' => 'https://x/?pageNumber=3', 'current_page' => 3]);

    $run->refresh();
    expect($run->cursor_url)->toBe('https://x/?pageNumber=3')
        ->and($run->current_page)->toBe(3);
});

test('штатно завершает прогон и очищает курсор', function () {
    $crawler = Crawler::factory()->create();
    $run = $this->manager->startOrResume($crawler);
    $this->manager->heartbeat($run, ['cursor_url' => 'https://x/?pageNumber=3', 'current_page' => 3]);

    $this->manager->complete($run);

    $run->refresh();
    expect($run->status)->toBe(CrawlerRunStatus::Completed)
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->cursor_url)->toBeNull()
        ->and($run->current_page)->toBeNull();
});

test('фиксирует падение с error-блоком, сохраняя курсор для resume', function () {
    $crawler = Crawler::factory()->create();
    $run = $this->manager->startOrResume($crawler);
    $this->manager->heartbeat($run, ['cursor_url' => 'https://x/?pageNumber=5', 'current_page' => 5]);

    $this->manager->fail($run, new RuntimeException('браузер упал'));

    $run->refresh();
    expect($run->status)->toBe(CrawlerRunStatus::Failed)
        ->and($run->attempts)->toBe(1)
        ->and($run->last_error)->toBe('браузер упал')
        ->and($run->last_error_at)->not->toBeNull()
        ->and($run->next_retry_at)->not->toBeNull()
        ->and($run->cursor_url)->toBe('https://x/?pageNumber=5')
        ->and($run->current_page)->toBe(5);

    expect($run)->toHaveLoggedMessage('%упал%');
});
