<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\ListingCursor;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Enums\Procurement\Crawler\RunStopKind;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Services\Procurement\CrawlerRunManager;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

test('первый startOrResume создаёт один running-прогон', function () {
    $crawler = Crawler::factory()->create();

    $run = app(CrawlerRunManager::class)->startOrResume($crawler);

    expect($run->status)->toBe(RunStatus::Running)
        ->and(Run::query()->where('crawler_id', $crawler->id)->count())->toBe(1);
});

test('повторный startOrResume резюмит тот же running, второго не заводит', function () {
    $crawler = Crawler::factory()->create();
    $manager = app(CrawlerRunManager::class);

    $first = $manager->startOrResume($crawler);
    $second = $manager->startOrResume($crawler);

    expect($second->id)->toBe($first->id)
        ->and(Run::query()->where('crawler_id', $crawler->id)->where('status', RunStatus::Running)->count())->toBe(1);
});

test('второй running руками падает на unique-индексе', function () {
    $crawler = Crawler::factory()->create();
    Run::factory()->for($crawler)->create(['status' => RunStatus::Running]);

    Run::factory()->for($crawler)->create(['status' => RunStatus::Running]);
})->throws(UniqueConstraintViolationException::class);

test('paused-прогон резюмится в running при startOrResume', function () {
    $crawler = Crawler::factory()->create();
    $paused = Run::factory()->for($crawler)->create(['status' => RunStatus::Paused]);

    $resumed = app(CrawlerRunManager::class)->startOrResume($crawler);

    expect($resumed->id)->toBe($paused->id)
        ->and($resumed->status)->toBe(RunStatus::Running);
});

test('recoverStale переводит протухший running в paused и не трогает свежий', function () {
    $crawler = Crawler::factory()->create();
    $stale = Run::factory()->for($crawler)->create([
        'status' => RunStatus::Running,
        'last_heartbeat_at' => Carbon::now()->subSeconds(600),
    ]);
    $fresh = Run::factory()->for(Crawler::factory()->create())->create([
        'status' => RunStatus::Running,
        'last_heartbeat_at' => Carbon::now(),
    ]);

    $recovered = app(CrawlerRunManager::class)->recoverStale();

    expect($recovered)->toBe(1)
        ->and($stale->refresh()->status)->toBe(RunStatus::Paused)
        ->and($fresh->refresh()->status)->toBe(RunStatus::Running);
});

test('crawler:recover-stale поднимает протухший прогон и печатает число поднятых', function () {
    $crawler = Crawler::factory()->create();
    $stale = Run::factory()->for($crawler)->create([
        'status' => RunStatus::Running,
        'last_heartbeat_at' => Carbon::now()->subSeconds(600),
    ]);

    $this->artisan('crawler:recover-stale')
        ->expectsOutputToContain('Поднято зависших прогонов: 1.')
        ->assertExitCode(Command::SUCCESS);

    expect($stale->refresh()->status)->toBe(RunStatus::Paused);
});

test('heartbeat обновляет last_heartbeat_at', function () {
    $run = Run::factory()->create(['last_heartbeat_at' => Carbon::now()->subMinutes(10)]);

    app(CrawlerRunManager::class)->heartbeat($run);

    expect($run->refresh()->last_heartbeat_at->diffInSeconds(Carbon::now()))->toBeLessThan(2);
});

test('updateCursor пишет курсор через ListingCursor::toArray', function () {
    $run = Run::factory()->create(['cursor' => null]);
    $cursor = new ListingCursor(blockFrom: 501, reachedKnown: true);

    app(CrawlerRunManager::class)->updateCursor($run, $cursor);

    $stored = ListingCursor::fromArray($run->refresh()->cursor->toArray());
    expect($stored->blockFrom)->toBe(501)
        ->and($stored->reachedKnown)->toBeTrue();
});

test('pause переводит running в paused, пишет stats.stop_reason и гасит запрос остановки', function () {
    $crawler = Crawler::factory()->create();
    $run = Run::factory()->for($crawler)->create([
        'status' => RunStatus::Running,
        'stop_requested_at' => Carbon::now(),
        'stop_kind' => RunStopKind::Pause,
    ]);

    app(CrawlerRunManager::class)->pause($run, 'Остановлено оператором (pause).');

    $run->refresh();
    expect($run->status)->toBe(RunStatus::Paused)
        ->and($run->finished_at)->toBeNull()
        ->and($run->stats['stop_reason'])->toBe('Остановлено оператором (pause).')
        ->and($run->stop_requested_at)->toBeNull()
        ->and($run->stop_kind)->toBeNull();
});

test('cancel переводит running в cancelled, ставит finished_at и пишет stats.stop_reason', function () {
    $crawler = Crawler::factory()->create();
    $run = Run::factory()->for($crawler)->create([
        'status' => RunStatus::Running,
        'stop_requested_at' => Carbon::now(),
        'stop_kind' => RunStopKind::Cancel,
    ]);

    app(CrawlerRunManager::class)->cancel($run, 'Остановлено оператором (cancel).');

    $run->refresh();
    expect($run->status)->toBe(RunStatus::Cancelled)
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->stats['stop_reason'])->toBe('Остановлено оператором (cancel).')
        ->and($run->stop_requested_at)->toBeNull();
});

test('recordCheckpoint наращивает stats.checkpoints, не трогая другие ключи', function () {
    $run = Run::factory()->create(['stats' => ['cap_hit' => true]]);

    app(CrawlerRunManager::class)->recordCheckpoint($run);
    app(CrawlerRunManager::class)->recordCheckpoint($run->refresh());

    $run->refresh();
    expect($run->stats['checkpoints'])->toBe(2)
        ->and($run->stats['cap_hit'])->toBeTrue();
});

test('complete и fail ставят терминальный статус и finished_at', function () {
    $crawler = Crawler::factory()->create();
    $manager = app(CrawlerRunManager::class);
    $run = Run::factory()->for($crawler)->create(['status' => RunStatus::Running]);

    $manager->complete($run);

    expect($run->refresh()->status)->toBe(RunStatus::Completed)
        ->and($run->finished_at)->not->toBeNull();

    $other = Run::factory()->for(Crawler::factory()->create())->create(['status' => RunStatus::Running]);
    $manager->fail($other, 'boom');

    expect($other->refresh()->status)->toBe(RunStatus::Failed)
        ->and($other->finished_at)->not->toBeNull()
        ->and($other->last_error)->toBe('boom');
});
