<?php

declare(strict_types=1);

use App\Enums\Procurement\Crawler\RunStatus;
use App\Filament\Resources\Procurement\Crawler\Runs\Pages\ListRuns;
use App\Filament\Resources\Procurement\Crawler\Runs\Pages\ViewRun;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Fetch;
use App\Models\Procurement\Crawler\Run;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

// `artisan()` без потребления возврата откладывает выполнение до __destruct() — форсируем
// синхронный прогон явным assertExitCode (тот же приём, что CrawlerRunActionsTest).
beforeEach(fn () => artisan('guard:sync-roles')->assertExitCode(0));

function actAsRunHistoryOperator(): User
{
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);
    actingAs($operator);

    return $operator;
}

test('таблица показывает статус и агрегаты собранных блоков', function (): void {
    actAsRunHistoryOperator();

    $crawler = Crawler::factory()->create();
    $run = Run::factory()->for($crawler)->create(['status' => RunStatus::Completed, 'finished_at' => now()]);

    Fetch::factory()->for($run, 'run')->create(['rows_new' => 10, 'rows_updated' => 3]);
    Fetch::factory()->for($run, 'run')->create(['rows_new' => 5, 'rows_updated' => 2]);

    Livewire::test(ListRuns::class)
        ->assertTableColumnStateSet('status', RunStatus::Completed, record: $run)
        ->assertTableColumnStateSet('fetches_count', 2, record: $run)
        ->assertTableColumnStateSet('fetches_rows_new_sum', 15, record: $run)
        ->assertTableColumnStateSet('fetches_rows_updated_sum', 5, record: $run);
});

test('прогон без блоков показывает нулевые агрегаты, а не падает', function (): void {
    actAsRunHistoryOperator();

    $crawler = Crawler::factory()->create();
    $run = Run::factory()->for($crawler)->create(['status' => RunStatus::Running]);

    Livewire::test(ListRuns::class)
        ->assertTableColumnStateSet('fetches_count', 0, record: $run)
        ->assertSuccessful();
});

test('фильтр по статусу отбирает ожидаемые прогоны', function (): void {
    actAsRunHistoryOperator();

    $crawler = Crawler::factory()->create();
    $running = Run::factory()->for($crawler)->create(['status' => RunStatus::Running]);
    $completed = Run::factory()->for($crawler)->create(['status' => RunStatus::Completed, 'finished_at' => now()]);

    Livewire::test(ListRuns::class)
        ->filterTable('status', RunStatus::Completed->value)
        ->assertCanSeeTableRecords([$completed])
        ->assertCanNotSeeTableRecords([$running]);
});

test('карточка прогона показывает причину остановки и расшифрованный курсор', function (): void {
    actAsRunHistoryOperator();

    $crawler = Crawler::factory()->create();
    $run = Run::factory()->for($crawler)->create([
        'status' => RunStatus::Completed,
        'finished_at' => now(),
        'cursor' => ['blockFrom' => 501, 'day' => '2026-08-01', 'priceStack' => ['0-100000', '100000-500000'], 'reachedKnown' => true],
        'stats' => ['stop_reason' => 'no_new_or_updated_rows', 'checkpoints' => 2],
    ]);

    Livewire::test(ViewRun::class, ['record' => $run->getKey()])
        ->assertSee('no_new_or_updated_rows')
        ->assertSee('501')
        ->assertSee('2026-08-01')
        ->assertSee('0-100000 → 100000-500000');
});

test('карточка прогона без stats показывает «—», а не ноль или пустую строку', function (): void {
    actAsRunHistoryOperator();

    $crawler = Crawler::factory()->create();
    $run = Run::factory()->for($crawler)->create(['status' => RunStatus::Running, 'cursor' => null, 'stats' => null]);

    Livewire::test(ViewRun::class, ['record' => $run->getKey()])
        ->assertSuccessful()
        ->assertSee('—');
});

test('число запросов списка не растёт с числом собранных блоков прогона', function (): void {
    actAsRunHistoryOperator();

    $crawler = Crawler::factory()->create();
    $singleBlockRun = Run::factory()->for($crawler)->create(['status' => RunStatus::Completed, 'finished_at' => now()]);
    Fetch::factory()->for($singleBlockRun, 'run')->create();

    // Прогрев процессных кэшей (права AzGuard и т.п.) ДО замера — иначе первый рендер платит
    // за холодный кэш, и сравнение считает это ростом от числа блоков.
    Livewire::test(ListRuns::class)->assertSuccessful();

    DB::enableQueryLog();
    Livewire::test(ListRuns::class)->assertSuccessful();
    $fewBlocksQueryCount = count(DB::getQueryLog());
    DB::flushQueryLog();

    Fetch::factory()->count(50)->for($singleBlockRun, 'run')->create();
    DB::flushQueryLog();

    Livewire::test(ListRuns::class)->assertSuccessful();
    $manyBlocksQueryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($manyBlocksQueryCount)->toBe($fewBlocksQueryCount);
});
