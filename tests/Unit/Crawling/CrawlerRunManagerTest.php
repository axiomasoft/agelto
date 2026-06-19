<?php

declare(strict_types=1);

use App\Enums\Crawler\CrawlerRunStatus;
use App\Models\Crawler\CrawlerRun;
use Sys\Models\SysLog;

it('создаёт активный прогон и пишет веху старта в журнал', function () {
    $crawler = crawlerProfile();

    $run = crawlerRunManager()->startOrResume($crawler);

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(CrawlerRunStatus::Running)
        ->and($run->started_at)->not->toBeNull()
        ->and($run->last_heartbeat_at)->not->toBeNull();

    expect(SysLog::query()
        ->where('entity_type', CrawlerRun::class)
        ->where('entity_id', $run->id)
        ->where('message', 'like', '%Старт прогона%')
        ->exists())->toBeTrue();
});

it('не создаёт второй прогон, пока активный жив (свежий heartbeat)', function () {
    $crawler = crawlerProfile();

    $first = crawlerRunManager()->startOrResume($crawler);
    $second = crawlerRunManager()->startOrResume($crawler);

    expect($second)->toBeNull()
        ->and(CrawlerRun::query()->where('crawler_id', $crawler->id)->count())->toBe(1)
        ->and($first->fresh()->status)->toBe(CrawlerRunStatus::Running);
});

it('возобновляет оборванный прогон (устаревший heartbeat), сохраняя курсор', function () {
    $crawler = crawlerProfile();

    $orphan = CrawlerRun::query()->create([
        'crawler_id' => $crawler->id,
        'status' => CrawlerRunStatus::Running,
        'attempts' => 0,
        'started_at' => now()->subHour(),
        'last_heartbeat_at' => now()->subHour(),
        'day_from' => '2025-03-04',
        'current_page' => 7,
    ]);

    $resumed = crawlerRunManager()->startOrResume($crawler);

    expect($resumed->id)->toBe($orphan->id)
        ->and($resumed->status)->toBe(CrawlerRunStatus::Running)
        ->and($resumed->attempts)->toBe(1)
        ->and($resumed->current_page)->toBe(7)
        ->and($resumed->day_from->toDateString())->toBe('2025-03-04')
        ->and(CrawlerRun::query()->where('crawler_id', $crawler->id)->count())->toBe(1);

    expect(SysLog::query()
        ->where('entity_id', $orphan->id)
        ->where('message', 'like', '%оборванный прогон%')
        ->exists())->toBeTrue();
});

it('двигает heartbeat и чекпойнт курсора', function () {
    $crawler = crawlerProfile();
    $run = crawlerRunManager()->startOrResume($crawler);

    crawlerRunManager()->heartbeat($run, ['cursor_url' => 'https://x/?pageNumber=3', 'current_page' => 3]);

    $run->refresh();
    expect($run->cursor_url)->toBe('https://x/?pageNumber=3')
        ->and($run->current_page)->toBe(3);
});

it('штатно завершает прогон и очищает курсор', function () {
    $crawler = crawlerProfile();
    $run = crawlerRunManager()->startOrResume($crawler);
    crawlerRunManager()->heartbeat($run, ['cursor_url' => 'https://x/?pageNumber=3', 'current_page' => 3]);

    crawlerRunManager()->complete($run);

    $run->refresh();
    expect($run->status)->toBe(CrawlerRunStatus::Completed)
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->cursor_url)->toBeNull()
        ->and($run->current_page)->toBeNull();
});

it('фиксирует падение с error-блоком, сохраняя курсор для resume', function () {
    $crawler = crawlerProfile();
    $run = crawlerRunManager()->startOrResume($crawler);
    crawlerRunManager()->heartbeat($run, ['cursor_url' => 'https://x/?pageNumber=5', 'current_page' => 5]);

    crawlerRunManager()->fail($run, new RuntimeException('браузер упал'));

    $run->refresh();
    expect($run->status)->toBe(CrawlerRunStatus::Failed)
        ->and($run->attempts)->toBe(1)
        ->and($run->last_error)->toBe('браузер упал')
        ->and($run->last_error_at)->not->toBeNull()
        ->and($run->next_retry_at)->not->toBeNull()
        ->and($run->cursor_url)->toBe('https://x/?pageNumber=5')
        ->and($run->current_page)->toBe(5);

    expect(SysLog::query()
        ->where('entity_id', $run->id)
        ->where('message', 'like', '%упал%')
        ->exists())->toBeTrue();
});
