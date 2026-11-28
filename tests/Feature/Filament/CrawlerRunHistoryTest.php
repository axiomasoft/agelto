<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisListingFilter;
use App\Dto\Procurement\Process\Outcomes\StageDeferred;
use App\Dto\Procurement\Process\Primitives\RetryAt;
use App\Enums\Procurement\Process\Core\AccountingRole;
use App\Enums\Procurement\Process\Core\RunWorkRelation;
use App\Enums\Procurement\Process\Outcomes\DeferralReason;
use App\Filament\Resources\Procurement\Process\Runs\Pages\ListProcessRuns;
use App\Filament\Resources\Procurement\Process\Work\Pages\ListWorkItems;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Process\ProcessRun;
use App\Models\Procurement\Process\WorkItem;
use App\Models\User;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Repositories\Procurement\Process\Work\WorkStoreRepository;
use App\Services\Platforms\Eis\EisListingPartitioner;
use App\Services\Procurement\Process\Execution\WorkFinalizer;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\ListingFixtures as F;

beforeEach(function (): void {
    F::bind();
    $this->artisan('guard:sync-roles')->assertSuccessful();
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $this->actingAs($operator);
});

function completedHistoryRun(): ProcessRun
{
    $f = F::run();
    F::finish($f['work'], $f['input'], F::page());
    $pass = F::next($f['crawl']) ?? throw new LogicException('Missing pass');
    F::finish($pass['work'], $pass['input'], F::page());

    return ProcessRun::query()->findOrFail($f['run']->value);
}

test('таблица показывает статус и агрегаты собранных блоков', function (): void {
    $run = completedHistoryRun();
    Livewire::test(ListProcessRuns::class)->assertTableColumnStateSet('status_text', 'Завершён', $run)
        ->assertTableColumnStateSet('required_total', 2, $run);
});

test('прогон без блоков показывает нулевые агрегаты, а не падает', function (): void {
    $run = ProcessRun::query()->findOrFail(LedgerFixtures::crawlBlock()['run']->value);
    Livewire::test(ListProcessRuns::class)->assertTableColumnStateSet('required_total', 0, $run)->assertSuccessful();
});

test('фильтр по статусу отбирает ожидаемые прогоны', function (): void {
    $complete = completedHistoryRun();
    $f = F::run(registry: Registry::query()->sole());
    $active = ProcessRun::query()->findOrFail($f['run']->value);
    Livewire::test(ListProcessRuns::class)->filterTable('discovery_outcome', 'complete')
        ->assertCanSeeTableRecords([$complete])->assertCanNotSeeTableRecords([$active]);
});

test('Process work history shows a bounded reason and absolute retry without platform cursor leakage', function (): void {
    $f = F::run();
    $retry = new RetryAt(new DateTimeImmutable('+10 minutes'));
    app(WorkFinalizer::class)->finish(FinalizeFixtures::running($f['work']),
        new StageDeferred(DeferralReason::RateLimit, $retry));
    $record = WorkItem::query()->findOrFail($f['work']->value->value);
    Livewire::test(ListWorkItems::class)->assertCanSeeTableRecords([$record])->assertSee('rate_limit')
        ->assertTableColumnStateSet('execution_attempts', 1, $record);
    expect($record->due_at->getTimestamp())->toBeGreaterThanOrEqual($retry->value->getTimestamp())
        ->toBeLessThanOrEqual($retry->value->getTimestamp() + 60);
});

test('Process history with no retry or reason renders the explicit placeholder', function (): void {
    F::run();
    Livewire::test(ListProcessRuns::class)->assertSuccessful()->assertSee('—');
    Livewire::test(ListWorkItems::class)->assertSuccessful()->assertSee('—');
});

test('число запросов списка не растёт с числом собранных блоков прогона', function (): void {
    $f = F::run();
    Livewire::test(ListProcessRuns::class)->assertSuccessful();
    DB::enableQueryLog();
    Livewire::test(ListProcessRuns::class)->assertSuccessful();
    $few = count(DB::getQueryLog());
    DB::disableQueryLog();
    $registry = Registry::query()->sole();
    DB::transaction(function () use ($f, $registry): void {
        $partitioner = new EisListingPartitioner;
        $filter = new EisListingFilter(['123']);
        $inputs = app(ListingStoreRepository::class)->allocate($f['crawl'],
            $partitioner->plan($filter, '2026-01-02', '2026-02-20'), $registry->id, 'fz44',
            $partitioner->snapshot($filter), $f['stream'], 10000);
        foreach ($inputs as $input) {
            $work = LedgerFixtures::storeWork($input, originRunId: $f['run']);
            app(WorkStoreRepository::class)->linkRun($f['run'], $work, RunWorkRelation::Created, AccountingRole::Required);
        }
    });
    DB::flushQueryLog();
    DB::enableQueryLog();
    Livewire::test(ListProcessRuns::class)->assertSuccessful();
    $many = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($many)->toBe($few)->and(DB::table('prc_process_work')->count())->toBe(51);
});
