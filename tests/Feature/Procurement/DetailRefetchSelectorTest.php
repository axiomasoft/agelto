<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\Outcomes\ArtifactRef;
use App\Dto\Procurement\Process\Outcomes\StageFailedItem;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Outcomes\ItemFailureReason;
use App\Enums\Procurement\ProcurementStatus;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Crawler\RefreshRepository;
use App\Services\Procurement\Process\Execution\WorkFeeder;
use App\Services\Procurement\Process\Execution\WorkFinalizer;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\DetailFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures as E;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\ListingFixtures as L;
use Tests\Support\ProcessCore\RefreshFixtures;

beforeEach(function (): void {
    E::authority([StageCode::Listing, StageCode::Detail, StageCode::StatusRefresh]);
    L::bindLocal();
    DetailFixtures::bindLocal();
});

/** @return array{p: Procurement, work: WorkId, crawl: ProcessUuid} */
function discoveredRefetch(): array
{
    $listing = L::run();
    L::finish($listing['work'], $listing['input'], L::page([L::summary('00046')]));
    $p = Procurement::query()->where('source_identity_value', '00046')->sole();
    $work = DB::table('prc_process_work')->where('stage', 'detail')->where('procurement_id', $p->id)->sole();

    return ['p' => $p, 'work' => new WorkId(new ProcessUuid($work->id)), 'crawl' => $listing['crawl']];
}

function rejectedRefetch(WorkId $work, bool $rerun = false): void
{
    $lease = $rerun ? FinalizeFixtures::rerun($work) : FinalizeFixtures::running($work);
    app(WorkFinalizer::class)->finish($lease, new StageFailedItem(ItemFailureReason::ContractRejected,
        new ArtifactRef('fixture', 'reject', LedgerFixtures::hash('reject'))));
}

test('listing discovery allocates initial detail work without a polling selector', function (): void {
    $f = discoveredRefetch();
    expect(E::work($f['work'])->state)->toBe('ready')
        ->and(DB::table('prc_operation_intents')->where('subject_id', (string) $f['p']->id)->where('stage', 'detail')->value('state'))->toBe('active')
        ->and(DB::table('prc_process_run_work')->where('work_id', $f['work']->value->value)->sole()->accounting_role)->toBe('required');
});

test('a newly published source head retains a detail obligation for the new generation', function (): void {
    $f = discoveredRefetch();
    $next = L::next($f['crawl']) ?? throw new LogicException('Missing next listing pass');
    L::finish($next['work'], $next['input'], L::page([L::summary('00046', 'Changed title')]));
    $p = $f['p']->refresh();
    expect($p->head_generation)->toBeGreaterThan(1)
        ->and(DB::table('prc_operation_intents')->where('subject_id', (string) $p->id)->where('stage', 'detail')
            ->where('expected_head_generation', $p->head_generation)->whereIn('state', ['active', 'pending'])->count())->toBe(1);
});

test('an elapsed item retry resumes the same durable detail work without a new intent', function (): void {
    $work = E::detailWork();
    rejectedRefetch($work);
    $before = E::work($work);
    expect($before->state)->toBe('deferred')->and($before->item_failures)->toBe(1)
        ->and(app(WorkFeeder::class)->tick('acquisition'))->toBe(0);
    $lease = FinalizeFixtures::rerun($work);
    expect($lease->workId->value->value)->toBe($work->value->value)
        ->and(E::work($work)->item_failures)->toBe(1)
        ->and(DB::table('prc_process_work')->count())->toBe(1);
});

test('due terminal procurements allocate a new full detail revalidation cycle', function (): void {
    $f = RefreshFixtures::dueDetail('periodic-awarded');
    $f['p']->update(['status' => ProcurementStatus::Awarded]);
    $work = app(RefreshRepository::class)->scheduleDetail($f['p']->id);
    expect($work)->not->toBeNull()->and($work?->value->value)->not->toBe($f['work']->value->value)
        ->and($f['p']->refresh()->detail_cycle_seq)->toBe(1)
        ->and(DB::table('prc_operation_intents')->where('id', $f['input']->intentId->value)->value('next_due_at'))->toBeNull();
});

test('recently completed detail without a due trigger allocates no new work', function (): void {
    $f = RefreshFixtures::dueDetail('future-cycle');
    DB::update("UPDATE prc_operation_intents SET next_due_at=clock_timestamp()+interval '1 day' WHERE id=?", [$f['input']->intentId->value]);
    $before = DB::table('prc_process_work')->count();
    expect(app(RefreshRepository::class)->due())->toBe([])
        ->and(DB::table('prc_process_work')->count())->toBe($before)
        ->and($f['p']->refresh()->detail_cycle_seq)->toBe(0);
});

test('automatic due scans cannot create another work to bypass an exhausted item budget', function (): void {
    $work = E::detailWork();
    rejectedRefetch($work);
    rejectedRefetch($work, true);
    rejectedRefetch($work, true);
    expect(E::work($work)->state)->toBe('failed_item')->and(E::work($work)->item_failures)->toBe(3)
        ->and(app(RefreshRepository::class)->due())->toBe([])
        ->and(DB::table('prc_process_work')->count())->toBe(1);
});

test('other procurement timestamps cannot bypass a future retry on the same immutable input', function (): void {
    $work = E::detailWork();
    rejectedRefetch($work);
    $before = E::work($work);
    DB::table('prc_procurements')->where('id', $before->procurement_id)->update(['current_revision_detected_at' => DB::raw('clock_timestamp()')]);
    expect(app(WorkFeeder::class)->tick('acquisition'))->toBe(0)
        ->and(app(RefreshRepository::class)->due())->toBe([])
        ->and(E::work($work)->state)->toBe('deferred')
        ->and(E::work($work)->due_at)->toBe($before->due_at)
        ->and(E::work($work)->item_failures)->toBe(1);
});

test('bounded periodic allocation preserves independently discovered initial detail work', function (): void {
    $discovery = discoveredRefetch();
    RefreshFixtures::dueDetail('periodic-other', $discovery['p']->registry);
    $allocated = app(RefreshRepository::class)->due(1);
    expect($allocated)->toHaveCount(1)
        ->and($allocated[0]->value->value)->not->toBe($discovery['work']->value->value)
        ->and(E::work($discovery['work'])->state)->toBe('admitted')
        ->and(DB::table('prc_process_work')->where('stage', 'detail')->whereIn('state', ['ready', 'admitted'])->count())->toBe(2);
});
