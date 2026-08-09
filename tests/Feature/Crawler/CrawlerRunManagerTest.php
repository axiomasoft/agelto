<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\ListingCursor;
use App\Enums\Procurement\Crawler\RunPurpose;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Enums\Procurement\Crawler\RunStopKind;
use App\Exceptions\Procurement\Crawler\CrawlerRunBusyException;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Services\Procurement\CrawlerRunManager;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Tests\Support\Postgres\ConcurrentTransactions;

test('первый startOrResume создаёт один running-прогон', function () {
    $crawler = Crawler::factory()->create();

    $run = app(CrawlerRunManager::class)->startOrResume(crawler: $crawler, purpose: RunPurpose::Profile, subjectKey: null);

    expect($run->status)->toBe(RunStatus::Running)
        ->and(Run::query()->where('crawler_id', $crawler->id)->count())->toBe(1);
});

test('повторный startOrResume резюмит тот же running, второго не заводит', function () {
    $crawler = Crawler::factory()->create();
    $manager = app(CrawlerRunManager::class);

    $first = $manager->startOrResume(crawler: $crawler, purpose: RunPurpose::Profile, subjectKey: null);
    $second = $manager->startOrResume(crawler: $crawler, purpose: RunPurpose::Profile, subjectKey: null);

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

    $resumed = app(CrawlerRunManager::class)->startOrResume(crawler: $crawler, purpose: RunPurpose::Profile, subjectKey: null);

    expect($resumed->id)->toBe($paused->id)
        ->and($resumed->status)->toBe(RunStatus::Running);
});

test('другой owner не получает running-прогон и не меняет его курсор', function () {
    $crawler = Crawler::factory()->create();
    $running = Run::factory()->for($crawler)->create([
        'purpose' => RunPurpose::Profile,
        'subject_key' => null,
        'cursor' => ['blockFrom' => 501],
    ]);
    $manager = app(CrawlerRunManager::class);

    expect(fn () => $manager->startOrResume(
        crawler: $crawler,
        purpose: RunPurpose::Delta,
        subjectKey: "fresh-crawler:{$crawler->id}",
    ))->toThrow(CrawlerRunBusyException::class)
        ->and(fn () => $manager->startOrResume(
            crawler: $crawler,
            purpose: RunPurpose::Backlog,
            subjectKey: 'fresh-run:1',
        ))->toThrow(CrawlerRunBusyException::class);

    expect($running->refresh()->purpose)->toBe(RunPurpose::Profile)
        ->and($running->subject_key)->toBeNull()
        ->and($running->cursor->all())->toBe(['blockFrom' => 501]);
});

test('backlog owner не резюмирует другой subject', function () {
    $crawler = Crawler::factory()->create();
    $running = Run::factory()->for($crawler)->create([
        'purpose' => RunPurpose::Backlog,
        'subject_key' => 'fresh-run:101',
    ]);

    expect(fn () => app(CrawlerRunManager::class)->startOrResume(
        crawler: $crawler,
        purpose: RunPurpose::Backlog,
        subjectKey: 'fresh-run:102',
    ))->toThrow(CrawlerRunBusyException::class);

    expect($running->refresh()->subject_key)->toBe('fresh-run:101')
        ->and($running->status)->toBe(RunStatus::Running);
});

test('два PostgreSQL worker одновременно стартуют одного owner в один run', function () {
    $crawler = ConcurrentTransactions::execute(<<<'PHP'
$crawler = \App\Models\Procurement\Crawler\Crawler::factory()->create();

return ['crawler_id' => $crawler->id];
PHP);

    try {
        $workers = ConcurrentTransactions::concurrently([
            'first' => <<<PHP
\$crawler = \App\Models\Procurement\Crawler\Crawler::findOrFail({$crawler['crawler_id']});
\$run = app(\App\Services\Procurement\CrawlerRunManager::class)->startOrResume(
    crawler: \$crawler,
    purpose: \App\Enums\Procurement\Crawler\RunPurpose::Delta,
    subjectKey: 'fresh-crawler:{$crawler['crawler_id']}',
);

return ['run_id' => \$run->id, 'backend_pid' => \Illuminate\Support\Facades\DB::scalar('select pg_backend_pid()')];
PHP,
            'second' => <<<PHP
\$crawler = \App\Models\Procurement\Crawler\Crawler::findOrFail({$crawler['crawler_id']});
\$run = app(\App\Services\Procurement\CrawlerRunManager::class)->startOrResume(
    crawler: \$crawler,
    purpose: \App\Enums\Procurement\Crawler\RunPurpose::Delta,
    subjectKey: 'fresh-crawler:{$crawler['crawler_id']}',
);

return ['run_id' => \$run->id, 'backend_pid' => \Illuminate\Support\Facades\DB::scalar('select pg_backend_pid()')];
PHP,
        ]);

        expect($workers['first']['run_id'])->toBe($workers['second']['run_id'])
            ->and($workers['first']['backend_pid'])->not->toBe($workers['second']['backend_pid']);
    } finally {
        ConcurrentTransactions::execute(<<<PHP
\App\Models\Procurement\Crawler\Run::query()->where('crawler_id', {$crawler['crawler_id']})->delete();
\App\Models\Procurement\Crawler\Crawler::query()->whereKey({$crawler['crawler_id']})->delete();

return ['cleaned' => true];
PHP);
    }
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
    $cursor = new ListingCursor(blockFrom: 501, reachedKnown: true, lastDedupSignature: 'durable-signature', blocksInInterval: 3);

    app(CrawlerRunManager::class)->updateCursor($run, $cursor);

    $stored = ListingCursor::fromArray($run->refresh()->cursor->toArray());
    expect($stored->blockFrom)->toBe(501)
        ->and($stored->reachedKnown)->toBeTrue()
        ->and($stored->lastDedupSignature)->toBe('durable-signature')
        ->and($stored->blocksInInterval)->toBe(3);
});

test('legacy cursor JSON получает безопасные defaults cap-detector', function () {
    $cursor = ListingCursor::fromArray(['blockFrom' => 501]);

    expect($cursor->lastDedupSignature)->toBeNull()
        ->and($cursor->blocksInInterval)->toBe(0);
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

test('complete на прогоне, который уже НЕ Running — тихий no-op, статус и finished_at не меняются', function () {
    $crawler = Crawler::factory()->create();
    $run = Run::factory()->for($crawler)->create(['status' => RunStatus::Paused, 'finished_at' => null]);

    app(CrawlerRunManager::class)->complete($run);

    $run->refresh();
    expect($run->status)->toBe(RunStatus::Paused)
        ->and($run->finished_at)->toBeNull();
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
