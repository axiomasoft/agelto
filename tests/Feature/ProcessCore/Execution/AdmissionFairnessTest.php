<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\Authority\FairnessCursor;
use App\Dto\Procurement\Process\Primitives\VersionedPayload;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Governance\ControlMode;
use App\Enums\Procurement\Process\Outcomes\AdmissionStatus;
use App\Models\Procurement\Document\Document;
use App\Repositories\Procurement\Process\Work\AdmissionRepository;
use App\Services\Procurement\Process\Execution\WorkFeeder;
use App\Services\Procurement\Process\Infrastructure\ProcessPayloadCodec;
use App\Services\Procurement\Process\Infrastructure\ProcessPolicy;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('admission-fairness');

/** @return list<string> work ids в порядке committed admissions (transition admitted). */
function admissionSequence(): array
{
    return DB::table('prc_process_transitions')->where('new_state', 'admitted')->where('previous_state', '<>', 'admitted')
        ->orderBy('occurred_at')->pluck('work_id')->all();
}

/** @return list<string> "cohort/stage" в порядке committed admissions. */
function admissionPairs(): array
{
    return array_map(function (string $id): string {
        $work = DB::table('prc_process_work')->where('id', $id)->sole();

        return $work->cohort_key.'/'.$work->stage;
    }, admissionSequence());
}

function admissionTicks(int $ticks): int
{
    $granted = 0;
    for ($tick = 0; $tick < $ticks; $tick++) {
        $granted += app(WorkFeeder::class)->tick('acquisition');
    }

    return $granted;
}

function admittedState(WorkId $work): string
{
    return ExecutionFixtures::work($work)->state;
}

test('admission атомарно создаёт group с точным lane set, dispatch generation, transition, projection и work_wake', function (): void {
    ExecutionFixtures::authority([StageCode::Listing]);
    $work = ExecutionFixtures::listingWork();

    expect(app(WorkFeeder::class)->tick('acquisition'))->toBe(1);

    $id = $work->value->value;
    $row = DB::selectOne(
        'SELECT state, dispatch_generation, transition_seq, generation, due_at, reason_code,
                extract(epoch FROM dispatch_claim_until - clock_timestamp()) AS visibility FROM prc_process_work WHERE id = ?',
        [$id],
    );
    $group = DB::table('prc_admission_groups')->where('work_id', $id)->sole();
    $lanes = DB::table('prc_process_lanes')->whereIn('lane_key', ['acquisition', 'acquisition.listing'])->orderBy('id')->pluck('id')->all();
    $members = DB::table('prc_process_reservations')->where('admission_group_id', $group->id)->orderBy('lane_id')->get();
    $transition = DB::table('prc_process_transitions')->where('work_id', $id)->where('entity_seq', 2)->sole();
    $wake = DB::table('prc_process_outbox')->where('dedup_key', 'wake:'.$id.':1')->sole();
    $cursor = DB::table('prc_process_lanes')->where('lane_key', 'acquisition')->value('fairness_cursor');

    expect($row->state)->toBe('admitted')
        ->and([$row->dispatch_generation, $row->transition_seq, $row->generation])->toBe([1, 2, 0])
        ->and($row->due_at)->toBeNull()->and($row->reason_code)->toBeNull()
        ->and((float) $row->visibility)->toBeGreaterThan(290.0)->toBeLessThanOrEqual(300.0)
        ->and([$group->state, $group->dispatch_generation, $group->version, $group->attempt_id])->toBe(['admitted', 1, 1, null])
        ->and(DB::selectOne("SELECT encode(required_lane_set_hash, 'hex') AS hash FROM prc_admission_groups WHERE id = ?", [$group->id])->hash)->toBe(app(ProcessPolicy::class)->requiredLaneSetHash(StageCode::Listing)->hex)
        ->and($members->pluck('lane_id')->all())->toBe($lanes)
        ->and($members->pluck('state')->unique()->all())->toBe(['admitted'])
        ->and($members->pluck('stage')->unique()->all())->toBe(['listing'])
        ->and([$transition->previous_state, $transition->new_state, $transition->actor_kind])->toBe(['ready', 'admitted', 'system'])
        ->and(DB::table('prc_process_outbox')->where('dedup_key', 'project:'.$transition->event_id)->where('kind', 'projection')->count())->toBe(1)
        ->and($wake->kind)->toBe('work_wake')
        ->and(json_decode($wake->payload, true))->toBe([
            'stage' => 'listing', 'work_id' => $id, 'message_id' => $wake->message_id, 'dispatch_generation' => 1, 'queue_schema_version' => 1,
        ])
        ->and(json_decode($cursor, true))->toBe(['last_cohort' => 'manual', 'last_stage_by_cohort' => ['manual' => 'listing']]);
});

test('parent и child W: child lane не выходит за свой W, parent W ограничивает сумму sibling stages', function (): void {
    ExecutionFixtures::authority([StageCode::Listing, StageCode::Detail], lanes: [
        'acquisition' => ['max_outstanding' => 3, 'max_running' => 3, 'low_watermark' => 2, 'refill_max' => 3],
        'acquisition.listing' => ['max_outstanding' => 2, 'max_running' => 2, 'low_watermark' => 1, 'refill_max' => 2],
    ]);
    $listing = array_map(fn (): WorkId => ExecutionFixtures::listingWork(), range(1, 4));

    expect(admissionTicks(3))->toBe(2)
        ->and(ExecutionFixtures::laneCounts('acquisition.listing'))->toBe(['outstanding' => 2, 'running' => 0])
        ->and(ExecutionFixtures::laneCounts('acquisition'))->toBe(['outstanding' => 2, 'running' => 0])
        ->and(array_count_values(array_map(admittedState(...), $listing)))->toBe(['admitted' => 2, 'ready' => 2]);

    $detail = array_map(fn (): WorkId => ExecutionFixtures::detailWork(), range(1, 3));

    expect(admissionTicks(3))->toBe(1)
        ->and(ExecutionFixtures::laneCounts('acquisition'))->toBe(['outstanding' => 3, 'running' => 0])
        ->and(ExecutionFixtures::laneCounts('acquisition.detail'))->toBe(['outstanding' => 1, 'running' => 0])
        ->and(array_count_values(array_map(admittedState(...), $detail)))->toBe(['admitted' => 1, 'ready' => 2])
        ->and(DB::table('prc_admission_groups')->where('state', 'admitted')->count())->toBe(3)
        ->and(DB::table('prc_process_reservations')->count())->toBe(6);

    // Прямой admit под locks тоже отвергает grant сверх parent W: pre-read не является authority.
    expect(app(AdmissionRepository::class)->admit($detail[1], FairnessCursor::empty(), 1)->status)->toBe(AdmissionStatus::CapacityFull)
        ->and(admittedState($detail[1]))->toBe('ready');
});

test('refill только при outstanding <= low watermark и не больше refill_max grants за tick', function (): void {
    ExecutionFixtures::authority([StageCode::Listing], lanes: [
        'acquisition' => ['max_outstanding' => 6, 'max_running' => 2, 'low_watermark' => 1, 'refill_max' => 2],
        'acquisition.listing' => ['max_outstanding' => 6, 'max_running' => 2, 'low_watermark' => 1, 'refill_max' => 2],
    ]);
    foreach (range(1, 5) as $ignored) {
        ExecutionFixtures::listingWork();
    }

    expect(app(WorkFeeder::class)->tick('acquisition'))->toBe(2)
        ->and(app(WorkFeeder::class)->tick('acquisition'))->toBe(0)
        ->and(ExecutionFixtures::laneCounts('acquisition')['outstanding'])->toBe(2);
});

test('cohort RR, затем stage RR внутри cohort: каждый eligible cohort и stage посещается за N и S admissions', function (): void {
    ExecutionFixtures::authority([StageCode::Listing, StageCode::Detail, StageCode::StatusRefresh], lanes: [
        'acquisition' => ['max_outstanding' => 20, 'max_running' => 4, 'low_watermark' => 19, 'refill_max' => 1],
    ]);
    foreach (['platform:aaa', 'platform:bbb'] as $cohort) {
        foreach (range(1, 2) as $ignored) {
            ExecutionFixtures::listingWork($cohort);
            ExecutionFixtures::detailWork($cohort);
            ExecutionFixtures::statusWork($cohort);
        }
    }

    expect(admissionTicks(8))->toBe(8)
        ->and(admissionPairs())->toBe([
            'platform:aaa/detail', 'platform:bbb/detail', 'platform:aaa/listing', 'platform:bbb/listing',
            'platform:aaa/status_refresh', 'platform:bbb/status_refresh', 'platform:aaa/detail', 'platform:bbb/detail',
        ])
        ->and(json_decode((string) DB::table('prc_process_lanes')->where('lane_key', 'acquisition')->value('fairness_cursor'), true))
        ->toBe(['last_cohort' => 'platform:bbb', 'last_stage_by_cohort' => ['platform:aaa' => 'detail', 'platform:bbb' => 'detail']]);
});

test('aged work идёт первым по created_at без priority override, затем platform по priority', function (): void {
    ExecutionFixtures::authority([StageCode::Listing], lanes: [
        'acquisition' => ['max_outstanding' => 20, 'max_running' => 4, 'low_watermark' => 19, 'refill_max' => 1],
        'acquisition.listing' => ['max_outstanding' => 10, 'max_running' => 2, 'low_watermark' => 9, 'refill_max' => 1],
    ]);
    $fresh = ExecutionFixtures::listingWork('platform:aaa', priority: 100);
    $urgent = ExecutionFixtures::listingWork('platform:aaa', priority: 10);
    $aged = ExecutionFixtures::listingWork('platform:aaa', priority: 200);
    $oldest = ExecutionFixtures::listingWork('platform:aaa', priority: 300);
    ExecutionFixtures::age($aged, 1000);
    ExecutionFixtures::age($oldest, 2000);
    ExecutionFixtures::age($urgent, 30);
    ExecutionFixtures::age($fresh, 60);

    expect(admissionTicks(4))->toBe(4)
        ->and(admissionSequence())->toBe([$oldest->value->value, $aged->value->value, $urgent->value->value, $fresh->value->value]);
});

test('manual non-aged cohort — FIFO по created_at, priority не обгоняет', function (): void {
    ExecutionFixtures::authority([StageCode::Listing], lanes: [
        'acquisition' => ['max_outstanding' => 20, 'max_running' => 4, 'low_watermark' => 19, 'refill_max' => 1],
    ]);
    $later = ExecutionFixtures::listingWork('manual', priority: 1);
    $earlier = ExecutionFixtures::listingWork('manual', priority: 500);
    ExecutionFixtures::age($later, 10);
    ExecutionFixtures::age($earlier, 20);

    expect(admissionTicks(2))->toBe(2)
        ->and(admissionSequence())->toBe([$earlier->value->value, $later->value->value]);
});

test('dynamic cohort arrival: scope создаёт отдельная ensureScopes, cap ceil(W/2) без отзыва выданных slots', function (): void {
    ExecutionFixtures::authority([StageCode::Listing], lanes: [
        'acquisition' => ['max_outstanding' => 4, 'max_running' => 4, 'low_watermark' => 3, 'refill_max' => 3],
        'acquisition.listing' => ['max_outstanding' => 4, 'max_running' => 2, 'low_watermark' => 3, 'refill_max' => 3],
    ]);
    $first = array_map(fn (): WorkId => ExecutionFixtures::listingWork('platform:aaa'), range(1, 5));

    expect(app(WorkFeeder::class)->tick('acquisition'))->toBe(3)
        ->and(DB::table('prc_control_scopes')->where('scope_key', 'acquisition:platform:aaa')->value('desired_mode'))->toBe('enabled');

    $second = array_map(fn (): WorkId => ExecutionFixtures::listingWork('platform:bbb'), range(1, 2));
    expect(DB::table('prc_control_scopes')->where('scope_key', 'acquisition:platform:bbb')->exists())->toBeFalse()
        ->and(app(WorkFeeder::class)->tick('acquisition'))->toBe(1);

    $scope = DB::table('prc_control_scopes')->where('scope_key', 'acquisition:platform:bbb')->sole();
    expect($scope->parent_id)->toBe(DB::table('prc_control_scopes')->where('scope_key', 'acquisition')->value('id'))
        ->and($scope->scope_kind)->toBe('platform')
        ->and(array_count_values(array_map(admittedState(...), $first)))->toBe(['admitted' => 3, 'ready' => 2])
        ->and(array_count_values(array_map(admittedState(...), $second)))->toBe(['admitted' => 1, 'ready' => 1])
        ->and(ExecutionFixtures::laneCounts('acquisition')['outstanding'])->toBe(4);

    // Даже при свободном W cohort A выше cap ceil(W/2): locked admit отвергает новый A grant.
    $cursor = app(ProcessPayloadCodec::class)->decodeFairnessCursor(
        new VersionedPayload((string) DB::table('prc_process_lanes')->where('lane_key', 'acquisition')->value('fairness_cursor'), 1),
    );
    DB::update("UPDATE prc_process_lanes SET max_outstanding = 6 WHERE lane_key IN ('acquisition', 'acquisition.listing')");
    expect(app(AdmissionRepository::class)->admit($first[4], $cursor, 2)->status)->toBe(AdmissionStatus::CohortCapped)
        ->and(app(AdmissionRepository::class)->admit($second[1], $cursor, 2)->status)->toBe(AdmissionStatus::Admitted);
});

test('единственный eligible cohort использует весь parent W', function (): void {
    ExecutionFixtures::authority([StageCode::Listing], lanes: [
        'acquisition' => ['max_outstanding' => 4, 'max_running' => 4, 'low_watermark' => 3, 'refill_max' => 4],
        'acquisition.listing' => ['max_outstanding' => 4, 'max_running' => 2, 'low_watermark' => 3, 'refill_max' => 4],
    ]);
    foreach (range(1, 6) as $ignored) {
        ExecutionFixtures::listingWork('platform:aaa');
    }

    expect(app(WorkFeeder::class)->tick('acquisition'))->toBe(4)
        ->and(ExecutionFixtures::laneCounts('acquisition')['outstanding'])->toBe(4);
});

test('deferred work admissible только после due_at; blocked не tick-eligible', function (): void {
    ExecutionFixtures::authority([StageCode::Listing]);
    $future = ExecutionFixtures::listingWork();
    $due = ExecutionFixtures::listingWork();
    $blocked = ExecutionFixtures::listingWork();
    DB::update("UPDATE prc_process_work SET state = 'deferred', reason_code = 'rate_limit', due_at = clock_timestamp() + interval '1 hour' WHERE id = ?", [$future->value->value]);
    DB::update("UPDATE prc_process_work SET state = 'deferred', reason_code = 'rate_limit', due_at = clock_timestamp() - interval '1 second' WHERE id = ?", [$due->value->value]);
    DB::update("UPDATE prc_process_work SET state = 'blocked', reason_code = 'paused' WHERE id = ?", [$blocked->value->value]);

    expect(app(WorkFeeder::class)->tick('acquisition'))->toBe(1)
        ->and(admittedState($due))->toBe('admitted')
        ->and(ExecutionFixtures::work($due)->reason_code)->toBeNull()
        ->and(DB::table('prc_process_work')->where('id', $due->value->value)->value('due_at'))->toBeNull()
        ->and(admittedState($future))->toBe('deferred')
        ->and(admittedState($blocked))->toBe('blocked')
        ->and(app(AdmissionRepository::class)->admit($future, FairnessCursor::empty()->advance('manual', StageCode::Listing), 1)->status)
        ->toBe(AdmissionStatus::NotEligible);
});

test('pause scope и held handler запрещают admission под locks; resume возвращает eligibility', function (): void {
    ExecutionFixtures::authority([StageCode::Listing]);
    $work = ExecutionFixtures::listingWork('platform:aaa');
    ExecutionFixtures::ensureWorkScopes(StageCode::Listing, 'platform:aaa');
    ExecutionFixtures::setMode('acquisition:stage:listing', ControlMode::Paused);

    expect(app(WorkFeeder::class)->tick('acquisition'))->toBe(0)
        ->and(app(AdmissionRepository::class)->admit($work, FairnessCursor::empty(), 1)->status)->toBe(AdmissionStatus::Paused)
        ->and(admittedState($work))->toBe('ready');

    ExecutionFixtures::setMode('acquisition:stage:listing', ControlMode::Enabled);
    expect(app(WorkFeeder::class)->tick('acquisition'))->toBe(1);

    $held = ExecutionFixtures::listingWork('platform:aaa');
    ExecutionFixtures::holdHandler(StageCode::Listing);
    $cursor = FairnessCursor::empty()->advance('platform:aaa', StageCode::Listing);

    expect(app(WorkFeeder::class)->tick('acquisition'))->toBe(0)
        ->and(app(AdmissionRepository::class)->admit($held, $cursor, 1)->status)->toBe(AdmissionStatus::HandlerUnavailable)
        ->and(admittedState($held))->toBe('ready')
        ->and(DB::table('prc_admission_groups')->count())->toBe(1);
});

test('pause platform scope останавливает только свой cohort', function (): void {
    ExecutionFixtures::authority([StageCode::Listing]);
    $paused = ExecutionFixtures::listingWork('platform:aaa');
    $other = ExecutionFixtures::listingWork('platform:bbb');
    ExecutionFixtures::ensureWorkScopes(StageCode::Listing, 'platform:aaa');
    ExecutionFixtures::setMode('acquisition:platform:aaa', ControlMode::Paused);

    expect(app(WorkFeeder::class)->tick('acquisition'))->toBe(1)
        ->and(admittedState($paused))->toBe('ready')
        ->and(admittedState($other))->toBe('admitted');
});

test('незарегистрированный handler и отсутствие authority row не дают grant', function (): void {
    ExecutionFixtures::authority([StageCode::Listing]);
    $download = ExecutionFixtures::store(LedgerFixtures::downloadInput(Document::factory()->create()->id, LedgerFixtures::uuid()), 'manual', 100);

    expect(app(WorkFeeder::class)->tick('download'))->toBe(0)
        ->and(app(AdmissionRepository::class)->admit($download, FairnessCursor::empty(), 1)->status)->toBe(AdmissionStatus::HandlerUnavailable)
        ->and(admittedState($download))->toBe('ready');
});

test('stale input при admission: fenced supersede без grant и без group', function (): void {
    ExecutionFixtures::authority([StageCode::Detail]);
    $work = ExecutionFixtures::detailWork();
    $procurement = DB::table('prc_process_work')->where('id', $work->value->value)->value('procurement_id');
    DB::update('UPDATE prc_procurements SET head_generation = head_generation + 1 WHERE id = ?', [$procurement]);

    expect(app(WorkFeeder::class)->tick('acquisition'))->toBe(0);

    $row = ExecutionFixtures::work($work);
    $transition = DB::table('prc_process_transitions')->where('work_id', $work->value->value)->orderByDesc('entity_seq')->first();
    expect([$row->state, $row->reason_code, $row->generation, $row->transition_seq])->toBe(['superseded', 'input_invalidated', 1, 2])
        ->and(DB::table('prc_admission_groups')->count())->toBe(0)
        ->and(DB::table('prc_process_outbox')->where('kind', 'work_wake')->count())->toBe(0)
        ->and([$transition->previous_state, $transition->new_state, $transition->generation])->toBe(['ready', 'superseded', 1]);
});
