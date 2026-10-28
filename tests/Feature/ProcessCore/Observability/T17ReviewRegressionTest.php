<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Process\ProcessRunState;
use App\Enums\Procurement\Process\StageCode;
use App\Filament\Resources\Procurement\Process\Runs\Pages\ListProcessRuns;
use App\Filament\Resources\Procurement\Process\Work\Pages\ListWorkItems;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Process\ProcessRun;
use App\Models\Procurement\Process\WorkItem;
use App\Models\User;
use App\Services\Procurement\Process\RunProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    artisan('guard:sync-roles');
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);
    actingAs($operator);
});

test('устаревшее подтверждение не отменяет работу', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    ExecutionFixtures::ensureWorkScopes(StageCode::Listing, 'manual');
    $work = ExecutionFixtures::listingWork();
    $record = WorkItem::query()->findOrFail($work->value->value);
    $original = (int) $record->transition_seq;
    $page = Livewire::test(ListWorkItems::class)
        ->mountTableAction('cancel_work', $record)
        ->setTableActionData(['reason' => 'confirmed original version']);

    // Isolate a concurrent authoritative version change between modal mount and submit.
    DB::table('prc_process_work')->where('id', $record->id)->increment('transition_seq');
    $page->callMountedTableAction();
    $after = DB::table('prc_process_work')->where('id', $record->id)->sole();

    expect($after->state)->toBe('ready');
});

test('устаревшее подтверждение не отменяет запуск', function (): void {
    $fixture = LedgerFixtures::crawlBlock();
    $record = ProcessRun::query()->findOrFail($fixture['run']->value);
    $original = (int) $record->transition_seq;
    $page = Livewire::test(ListProcessRuns::class)
        ->mountTableAction('cancel_run', $record)
        ->setTableActionData(['reason' => 'confirmed original version']);

    DB::table('prc_process_runs')->where('id', $record->id)->increment('transition_seq');
    $page->callMountedTableAction();
    $after = DB::table('prc_process_runs')->where('id', $record->id)->sole();

    expect($after->cancel_requested_at)->toBeNull();
});

test('таблица отличает запуск с проблемами от успешного', function (string $state, string $outcome): void {
    $ids = [];
    foreach ([['succeeded', 'complete'], [$state, $outcome]] as [$workState, $discovery]) {
        $fixture = LedgerFixtures::crawlBlock();
        $overrides = ['state' => $workState];
        if ($workState !== 'succeeded') {
            $overrides['reason_code'] = 'review_fixture';
        }
        $work = LedgerFixtures::insertRawWork($fixture['block'], $overrides);
        DB::table('prc_process_run_work')->insert(['run_id' => $fixture['run']->value,
            'work_id' => $work->value, 'relation' => 'created', 'accounting_role' => 'required']);
        DB::table('prc_process_runs')->where('id', $fixture['run']->value)->update([
            'discovery_outcome' => $discovery, 'discovery_closed_at' => DB::raw('clock_timestamp()'),
            'discovery_reason_code' => $discovery === 'incomplete' ? 'source_cap' : null,
        ]);
        $ids[] = $fixture['run'];
    }
    $page = Livewire::test(ListProcessRuns::class)->instance();
    $labels = [];
    foreach ($ids as $id) {
        $record = $page->getTableRecord($id->value);
        $labels[] = $page->getTable()->getColumn('status_text')->record($record)->getState();
    }
    $summary = app(RunProjector::class)->summary(new ProcessUuid($ids[1]->value));

    expect($summary?->state)->toBe(ProcessRunState::CompletedWithIssues)
        ->and($labels[1])->not->toBe($labels[0]);
})->with([
    'failed required work' => ['failed_item', 'complete'],
    'cancelled required work' => ['cancelled', 'complete'],
    'superseded required work without successor' => ['superseded', 'complete'],
    'incomplete discovery' => ['succeeded', 'incomplete'],
]);

test('длинная причина отклоняется формой без исключения', function (string $target): void {
    if ($target === 'work') {
        FinalizeFixtures::authority([StageCode::Listing]);
        ExecutionFixtures::ensureWorkScopes(StageCode::Listing, 'manual');
        $work = ExecutionFixtures::listingWork();
        $record = WorkItem::query()->findOrFail($work->value->value);
        $page = Livewire::test(ListWorkItems::class);
        $action = 'cancel_work';
    } else {
        $fixture = LedgerFixtures::crawlBlock();
        $record = ProcessRun::query()->findOrFail($fixture['run']->value);
        $page = Livewire::test(ListProcessRuns::class);
        $action = 'cancel_run';
    }

    $thrown = null;
    try {
        $page->callTableAction($action, $record, ['reason' => str_repeat('a', 201)]);
    } catch (Throwable $error) {
        $thrown = $error;
    }

    expect($thrown)->toBeNull();
})->with(['work', 'run']);
