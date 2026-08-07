<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\EisSearchFilter;
use App\Dto\Procurement\Crawler\ListingCursor;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Enums\Procurement\Crawler\RunStopKind;
use App\Exceptions\Procurement\Crawler\ArchiveWindowMissing;
use App\Exceptions\Procurement\Crawler\CrawlerRunAlreadyActive;
use App\Filament\Resources\Procurement\Crawler\Crawlers\Pages\ListCrawlers;
use App\Filament\Resources\Procurement\Crawler\Runs\Pages\ListRuns;
use App\Filament\Resources\Procurement\Crawler\Runs\Pages\ViewRun;
use App\Guards\Admin\Roles\AnalystRole;
use App\Guards\Admin\Roles\OperatorRole;
use App\Jobs\Procurement\CrawlRunJob;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Models\User;
use App\Services\Procurement\Crawler\StartCrawlerRunAction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

// `artisan()` без потребления возврата откладывает выполнение до __destruct() — форсируем
// синхронный прогон явным assertExitCode (тот же приём, что FixtureRunScreenTest).
beforeEach(fn () => artisan('guard:sync-roles')->assertExitCode(0));

function actAsRunActionsOperator(): User
{
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);
    actingAs($operator);

    return $operator;
}

test('оператор видит «Запустить» на профиле без активного и приостановленного прогона', function (): void {
    actAsRunActionsOperator();

    $crawler = Crawler::factory()->create();

    Livewire::test(ListCrawlers::class)
        ->assertTableActionVisible('start', record: $crawler)
        ->assertTableActionHidden('resume', record: $crawler);
});

test('«Запустить» ставит CrawlRunJob(mode: профиля) в очередь', function (): void {
    actAsRunActionsOperator();
    Queue::fake();

    $crawler = Crawler::factory()->create(['mode' => CrawlerMode::Fresh]);

    Livewire::test(ListCrawlers::class)
        ->callTableAction('start', record: $crawler)
        ->assertNotified('Поставлено в очередь');

    Queue::assertPushed(CrawlRunJob::class, fn (CrawlRunJob $job): bool => $job->crawlerId === $crawler->id && $job->mode === CrawlerMode::Fresh);
});

test('«Запустить» при активном прогоне не виден на строке профиля', function (): void {
    actAsRunActionsOperator();

    $crawler = Crawler::factory()->create();
    Run::factory()->for($crawler)->create(['status' => RunStatus::Running]);

    Livewire::test(ListCrawlers::class)
        ->assertTableActionHidden('start', record: $crawler)
        ->assertTableActionHidden('restart', record: $crawler)
        ->assertTableActionHidden('resume', record: $crawler);
});

test('прямой вызов StartCrawlerRunAction на активном прогоне отказывает и не ставит второй job', function (): void {
    Queue::fake();

    $crawler = Crawler::factory()->create();
    $activeRun = Run::factory()->for($crawler)->create(['status' => RunStatus::Running]);

    expect(fn () => app(StartCrawlerRunAction::class)->start($crawler))
        ->toThrow(CrawlerRunAlreadyActive::class, "уже идёт прогон #{$activeRun->id}");

    Queue::assertNotPushed(CrawlRunJob::class);
});

test('«Продолжить» видна только когда у профиля есть приостановленный прогон', function (): void {
    actAsRunActionsOperator();

    $crawler = Crawler::factory()->create();
    Run::factory()->for($crawler)->create(['status' => RunStatus::Paused]);

    Livewire::test(ListCrawlers::class)
        ->assertTableActionVisible('resume', record: $crawler)
        ->assertTableActionHidden('start', record: $crawler);
});

test('«Запустить с начала» обнуляет курсор приостановленного прогона', function (): void {
    actAsRunActionsOperator();
    Queue::fake();

    $crawler = Crawler::factory()->create();
    $run = Run::factory()->for($crawler)->create([
        'status' => RunStatus::Paused,
        'cursor' => (new ListingCursor(blockFrom: 7, reachedKnown: true))->toArray(),
    ]);

    Livewire::test(ListCrawlers::class)
        ->callTableAction('restart', record: $crawler)
        ->assertNotified('Поставлено в очередь');

    $cursor = $run->refresh()->cursor->toArray();
    $expectedCursor = (new ListingCursor)->toArray();
    ksort($cursor);
    ksort($expectedCursor);

    expect($cursor)->toBe($expectedCursor);

    Queue::assertPushed(CrawlRunJob::class);
});

test('«Остановить» на строке прогона пишет запрос кооперативной остановки', function (): void {
    actAsRunActionsOperator();

    $crawler = Crawler::factory()->create();
    $run = Run::factory()->for($crawler)->create(['status' => RunStatus::Running]);

    Livewire::test(ListRuns::class)
        ->callTableAction('pause', record: $run)
        ->assertNotified('Остановка запрошена — прогон завершится на текущем блоке');

    $run->refresh();

    expect($run->stop_kind)->toBe(RunStopKind::Pause)
        ->and($run->stop_requested_at)->not->toBeNull()
        ->and($run->status)->toBe(RunStatus::Running);
});

test('«Отменить» на карточке прогона пишет запрос кооперативной остановки', function (): void {
    $operator = actAsRunActionsOperator();

    $crawler = Crawler::factory()->create();
    $run = Run::factory()->for($crawler)->create(['status' => RunStatus::Running]);

    Livewire::test(ViewRun::class, ['record' => $run->getKey()])
        ->callAction('cancel')
        ->assertNotified('Остановка запрошена — прогон завершится на текущем блоке');

    $run->refresh();

    expect($run->stop_kind)->toBe(RunStopKind::Cancel)
        ->and($run->stop_requested_by)->toBe($operator->id);
});

test('«Остановить»/«Отменить» не видны на терминальном прогоне', function (): void {
    actAsRunActionsOperator();

    $crawler = Crawler::factory()->create();
    $run = Run::factory()->for($crawler)->create(['status' => RunStatus::Completed, 'finished_at' => now()]);

    Livewire::test(ListRuns::class)
        ->assertTableActionHidden('pause', record: $run)
        ->assertTableActionHidden('cancel', record: $run);
});

test('повторный запрос того же вида остановки — no-op, второй запрос другого вида меняет kind', function (): void {
    actAsRunActionsOperator();

    $crawler = Crawler::factory()->create();
    $run = Run::factory()->for($crawler)->create(['status' => RunStatus::Running]);

    $page = Livewire::test(ListRuns::class);

    $page->callTableAction('pause', record: $run);
    $firstRequestedAt = $run->refresh()->stop_requested_at;

    $page->callTableAction('pause', record: $run);
    expect($run->refresh()->stop_requested_at->equalTo($firstRequestedAt))->toBeTrue();

    $page->callTableAction('cancel', record: $run);
    expect($run->refresh()->stop_kind)->toBe(RunStopKind::Cancel);
});

test('без RunPermission::Update кнопки остановки прогона не видны', function (): void {
    $analyst = User::factory()->create();
    $analyst->assignRole(AnalystRole::class);
    actingAs($analyst);

    $crawler = Crawler::factory()->create();
    $run = Run::factory()->for($crawler)->create(['status' => RunStatus::Running]);

    Livewire::test(ListRuns::class)
        ->assertTableActionHidden('pause', record: $run)
        ->assertTableActionHidden('cancel', record: $run);
});

/**
 * P3.8 (D113): профиль в кейсах архивного окна заводится ЯВНО с `mode: CrawlerMode::Archive` —
 * дефолт `CrawlerFactory` равен `Fresh`, и именно поэтому окно раньше не тестировалось (Анти-
 * самообман item'а).
 */
function crawlerArchiveWithWindow(): Crawler
{
    return Crawler::factory()
        ->filter(new EisSearchFilter(
            okpd2Codes: ['43'],
            publishedFrom: CarbonImmutable::parse('2025-01-01'),
            publishedTo: CarbonImmutable::parse('2025-12-31'),
        ))
        ->create(['mode' => CrawlerMode::Archive]);
}

test('archive-профиль с окном: «Запустить» ставит CrawlRunJob(mode: Archive) с окном профиля', function (): void {
    actAsRunActionsOperator();
    Queue::fake();

    $crawler = crawlerArchiveWithWindow();

    Livewire::test(ListCrawlers::class)
        ->callTableAction('start', record: $crawler)
        ->assertNotified('Поставлено в очередь');

    Queue::assertPushed(CrawlRunJob::class, fn (CrawlRunJob $job): bool => $job->crawlerId === $crawler->id
        && $job->mode === CrawlerMode::Archive
        && str_starts_with((string) $job->from, '2025-01-01')
        && str_starts_with((string) $job->to, '2025-12-31'));
});

test('archive-профиль с окном: «Запустить с начала» обнуляет курсор и ставит job с окном профиля', function (): void {
    actAsRunActionsOperator();
    Queue::fake();

    $crawler = crawlerArchiveWithWindow();
    Run::factory()->for($crawler)->create([
        'status' => RunStatus::Paused,
        'cursor' => (new ListingCursor(blockFrom: 7, reachedKnown: true))->toArray(),
    ]);

    Livewire::test(ListCrawlers::class)
        ->callTableAction('restart', record: $crawler)
        ->assertNotified('Поставлено в очередь');

    Queue::assertPushed(CrawlRunJob::class, fn (CrawlRunJob $job): bool => $job->crawlerId === $crawler->id
        && $job->mode === CrawlerMode::Archive
        && str_starts_with((string) $job->from, '2025-01-01')
        && str_starts_with((string) $job->to, '2025-12-31'));
});

test('archive-профиль с окном: «Продолжить» ставит job с окном профиля', function (): void {
    actAsRunActionsOperator();
    Queue::fake();

    $crawler = crawlerArchiveWithWindow();
    Run::factory()->for($crawler)->create(['status' => RunStatus::Paused]);

    Livewire::test(ListCrawlers::class)
        ->callTableAction('resume', record: $crawler)
        ->assertNotified('Поставлено в очередь');

    Queue::assertPushed(CrawlRunJob::class, fn (CrawlRunJob $job): bool => $job->crawlerId === $crawler->id
        && $job->mode === CrawlerMode::Archive
        && str_starts_with((string) $job->from, '2025-01-01')
        && str_starts_with((string) $job->to, '2025-12-31'));
});

test('archive-профиль БЕЗ окна: «Запустить» отказывает, job не поставлен и прогон не создан', function (): void {
    actAsRunActionsOperator();
    Queue::fake();

    $crawler = Crawler::factory()
        ->filter(new EisSearchFilter(okpd2Codes: ['43']))
        ->create(['mode' => CrawlerMode::Archive]);

    Livewire::test(ListCrawlers::class)
        ->callTableAction('start', record: $crawler)
        ->assertNotified(ArchiveWindowMissing::forCrawler($crawler)->getMessage());

    Queue::assertNotPushed(CrawlRunJob::class);
    expect(Run::query()->where('crawler_id', $crawler->id)->count())->toBe(0);
});

test('archive-профиль с окном: «Остановить» пишет запрос кооперативной остановки', function (): void {
    actAsRunActionsOperator();

    $crawler = crawlerArchiveWithWindow();
    $run = Run::factory()->for($crawler)->create(['status' => RunStatus::Running]);

    Livewire::test(ListRuns::class)
        ->callTableAction('pause', record: $run)
        ->assertNotified('Остановка запрошена — прогон завершится на текущем блоке');

    $run->refresh();

    expect($run->stop_kind)->toBe(RunStopKind::Pause)
        ->and($run->stop_requested_at)->not->toBeNull()
        ->and($run->status)->toBe(RunStatus::Running);
});

test('archive-профиль с окном: «Отменить» пишет запрос кооперативной остановки', function (): void {
    $operator = actAsRunActionsOperator();

    $crawler = crawlerArchiveWithWindow();
    $run = Run::factory()->for($crawler)->create(['status' => RunStatus::Running]);

    Livewire::test(ViewRun::class, ['record' => $run->getKey()])
        ->callAction('cancel')
        ->assertNotified('Остановка запрошена — прогон завершится на текущем блоке');

    $run->refresh();

    expect($run->stop_kind)->toBe(RunStopKind::Cancel)
        ->and($run->stop_requested_by)->toBe($operator->id);
});
