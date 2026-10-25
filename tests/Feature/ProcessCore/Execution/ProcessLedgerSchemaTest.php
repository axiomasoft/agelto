<?php

declare(strict_types=1);

use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\WorkState;
use App\Models\Procurement\Procurement;
use App\Services\Procurement\Process\SchemaSupportedStages;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('t01', 'p16');

/** @return array<string, string> constraint name → pg_get_constraintdef */
function processLedgerConstraints(string $table): array
{
    return collect(DB::select(
        'SELECT k.conname, pg_get_constraintdef(k.oid) AS definition FROM pg_constraint k
         JOIN pg_class c ON c.oid = k.conrelid WHERE c.relname = ? ORDER BY k.conname',
        [$table],
    ))->mapWithKeys(fn (object $row): array => [$row->conname => $row->definition])->all();
}

/** @return list<string> literal values of a `col = ANY (ARRAY[...])` CHECK */
function processLedgerCheckValues(string $definition): array
{
    preg_match_all("/'([^']+)'::text/", $definition, $matches);

    return $matches[1];
}

test('P1.6 baseline создаёт весь ledger в порядке FK и не создаёт prc_process_controls', function (): void {
    $tables = collect(DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'"))->pluck('tablename');

    expect($tables)->toContain(
        'prc_control_scopes', 'prc_process_lanes', 'prc_process_runs', 'prc_process_incidents', 'prc_process_handler_holds',
        'prc_crawl_runs', 'prc_crawl_blocks', 'prc_operation_intents', 'prc_process_work', 'prc_process_attempts',
        'prc_admission_groups', 'prc_process_reservations', 'prc_process_run_work', 'prc_process_transitions',
        'prc_process_outbox', 'prc_process_daemon_heartbeats', 'prc_request_attempts', 'prc_observations', 'prc_observation_applications',
    )
        // Нет отдельной prc_process_controls; carriers следующих owners не создаются заранее (schema-staging).
        ->and($tables->intersect(['prc_process_controls', 'prc_network_demands', 'prc_analysis_windows', 'prc_process_projection_receipts'])->all())->toBe([]);

    $foreignKeys = collect(DB::select(
        "SELECT c.relname AS child, f.relname AS parent, k.confdeltype AS on_delete FROM pg_constraint k
         JOIN pg_class c ON c.oid = k.conrelid JOIN pg_class f ON f.oid = k.confrelid
         WHERE k.contype = 'f' AND c.relname::text = ANY (?::text[])",
        ['{prc_process_work,prc_process_reservations,prc_crawl_blocks,prc_process_attempts}'],
    ))->map(fn (object $row): string => $row->child.'→'.$row->parent.':'.$row->on_delete)->all();

    // Intents до work FK; реальные subjects; CASCADE только у composition child reservation→group.
    expect($foreignKeys)->toContain(
        'prc_process_work→prc_operation_intents:a', 'prc_process_work→prc_crawl_blocks:a', 'prc_process_work→prc_procurements:a',
        'prc_process_work→prc_documents:a', 'prc_process_work→prc_platforms:a', 'prc_process_work→prc_process_runs:a',
        'prc_crawl_blocks→prc_crawl_runs:a', 'prc_process_attempts→prc_process_work:a',
        'prc_process_reservations→prc_admission_groups:c', 'prc_process_reservations→prc_process_lanes:a',
    )->and(collect($foreignKeys)->filter(fn (string $fk): bool => str_ends_with($fk, ':c'))->values()->all())
        ->toBe(['prc_process_reservations→prc_admission_groups:c']);

    // Future owner columns без FK на ещё не созданные таблицы.
    expect(collect($foreignKeys)->filter(fn (string $fk): bool => str_contains($fk, 'version') || str_contains($fk, 'analysis'))->all())->toBe([]);
});

test('partial indexes due lease claim fairness provider recovery и fault budget существуют с точными predicates', function (): void {
    $indexes = collect(DB::select(
        "SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = 'public'
           AND tablename IN ('prc_process_work','prc_process_attempts','prc_admission_groups','prc_operation_intents','prc_process_incidents','prc_process_outbox','prc_process_transitions')",
    ))->mapWithKeys(fn (object $row): array => [$row->indexname => $row->indexdef]);

    expect($indexes['prc_process_work_due_idx'])->toEndWith("(due_at, priority, id) WHERE (state = ANY (ARRAY['ready'::text, 'deferred'::text]))")
        ->and($indexes['prc_process_work_lease_idx'])->toEndWith("(lease_until, id) WHERE (state = 'running'::text)")
        ->and($indexes['prc_process_work_claim_idx'])->toEndWith("(dispatch_claim_until, id) WHERE (state = 'admitted'::text)")
        ->and($indexes['prc_process_work_fairness_idx'])->toEndWith("(stage, cohort_key, created_at, id) WHERE (state = ANY (ARRAY['ready'::text, 'deferred'::text]))")
        ->and($indexes['prc_process_work_provider_recovery_due_idx'])->toEndWith("(provider_recovery_due_at, id) WHERE ((state = 'blocked'::text) AND (reason_code = 'provider_result_unknown'::text))")
        ->and($indexes['prc_process_attempts_work_fault_idx'])->toEndWith('(work_id, handler_version) WHERE ((fault_signature IS NOT NULL) AND (finished_at IS NOT NULL))')
        ->and($indexes['prc_process_attempts_fault_policy_pending_idx'])->toEndWith('(finished_at, id) WHERE ((fault_signature IS NOT NULL) AND (fault_policy_checked_at IS NULL))')
        ->and($indexes['prc_admission_groups_active_work_unique'])->toStartWith('CREATE UNIQUE INDEX')->toEndWith("(work_id) WHERE (state <> 'released'::text)")
        ->and($indexes['prc_operation_intents_one_active_unique'])->toStartWith('CREATE UNIQUE INDEX')->toEndWith("(stage, subject_kind, subject_id) WHERE (state = 'active'::text)")
        ->and($indexes['prc_process_incidents_active_fingerprint_unique'])->toEndWith("(fingerprint) WHERE (state <> 'resolved'::text)")
        ->and($indexes['prc_process_outbox_pending_idx'])->toEndWith('(kind, available_at, message_id) WHERE (published_at IS NULL)')
        ->and($indexes['prc_process_transitions_incident_seq_unique'])->toEndWith('(incident_id, entity_seq) WHERE (incident_id IS NOT NULL)');
});

test('stage CHECK всех stage-bearing таблиц совпадает с SchemaSupportedStages, а work state CHECK — с девятью WorkState', function (): void {
    $supported = array_map(fn (StageCode $stage): string => $stage->value, SchemaSupportedStages::stages());

    expect($supported)->toBe(['listing', 'detail', 'status_refresh', 'download']);
    foreach (['prc_process_work', 'prc_operation_intents', 'prc_process_reservations', 'prc_process_handler_holds'] as $table) {
        expect(processLedgerCheckValues(processLedgerConstraints($table)[$table.'_stage_check']))->toBe($supported, $table);
    }
    expect(processLedgerCheckValues(processLedgerConstraints('prc_process_work')['prc_process_work_state_check']))
        ->toBe(array_column(WorkState::cases(), 'value'));
    foreach ([StageCode::Prepare, StageCode::Screening, StageCode::Extraction, StageCode::Publication] as $stage) {
        expect(SchemaSupportedStages::supports($stage))->toBeFalse();
    }
});

test('owner tuple CHECK требует ровно один owner своей стадии, а FK не принимает вымышленный subject', function (): void {
    $subject = LedgerFixtures::crawlBlock();
    $procurementId = (int) Procurement::factory()->create()->getKey();

    expect(LedgerFixtures::sqlState(fn () => LedgerFixtures::insertRawWork($subject['block'])))->toBeNull()
        // Второй owner у listing, чужой owner, пустой owner.
        ->and(LedgerFixtures::sqlState(fn () => LedgerFixtures::insertRawWork($subject['block'], ['procurement_id' => $procurementId])))->toBe('23514')
        ->and(LedgerFixtures::sqlState(fn () => LedgerFixtures::insertRawWork($subject['block'], ['crawl_block_id' => null, 'procurement_id' => $procurementId])))->toBe('23514')
        ->and(LedgerFixtures::sqlState(fn () => LedgerFixtures::insertRawWork($subject['block'], ['stage' => 'detail', 'crawl_block_id' => null])))->toBe('23514')
        ->and(LedgerFixtures::sqlState(fn () => LedgerFixtures::insertRawWork($subject['block'], ['stage' => 'detail', 'crawl_block_id' => null, 'procurement_id' => $procurementId])))->toBeNull()
        // Future stage недопустима до P3.1 даже с её owner column; future owner без FK тоже не принимается.
        ->and(LedgerFixtures::sqlState(fn () => LedgerFixtures::insertRawWork($subject['block'], ['stage' => 'prepare', 'crawl_block_id' => null, 'document_version_id' => 7])))->toBe('23514')
        ->and(LedgerFixtures::sqlState(fn () => LedgerFixtures::insertRawWork($subject['block'], ['document_version_id' => 7])))->toBe('23514')
        // Нет fake owner rows: несуществующий crawl block/procurement отвергается FK.
        ->and(LedgerFixtures::sqlState(fn () => LedgerFixtures::insertRawWork(LedgerFixtures::uuid())))->toBe('23503')
        ->and(LedgerFixtures::sqlState(fn () => LedgerFixtures::insertRawWork($subject['block'], ['stage' => 'status_refresh', 'crawl_block_id' => null, 'procurement_id' => $procurementId + 1000])))->toBe('23503');
});

test('state lease claim due и provider recovery CHECK отвергают несогласованные сочетания', function (string $case, array $overrides, ?string $expected): void {
    $subject = LedgerFixtures::crawlBlock();

    expect(LedgerFixtures::sqlState(fn () => LedgerFixtures::insertRawWork($subject['block'], $overrides)))->toBe($expected, $case);
})->with([
    'running с token и lease' => ['running', ['state' => 'running', 'owner_token' => '01900000-0000-7000-8000-000000000001', 'lease_until' => '2026-10-25 10:00:00+00'], null],
    'running без token' => ['running-no-token', ['state' => 'running', 'lease_until' => '2026-10-25 10:00:00+00'], '23514'],
    'ready с остатком lease' => ['ready-lease', ['lease_until' => '2026-10-25 10:00:00+00'], '23514'],
    'admitted без claim deadline' => ['admitted', ['state' => 'admitted'], '23514'],
    'admitted с claim deadline' => ['admitted-ok', ['state' => 'admitted', 'dispatch_claim_until' => '2026-10-25 10:00:00+00'], null],
    'ready с claim deadline' => ['ready-claim', ['dispatch_claim_until' => '2026-10-25 10:00:00+00'], '23514'],
    'deferred без due_at' => ['deferred', ['state' => 'deferred', 'reason_code' => 'rate_limit'], '23514'],
    'deferred с due_at' => ['deferred-ok', ['state' => 'deferred', 'reason_code' => 'rate_limit', 'due_at' => '2026-10-25 10:00:00+00'], null],
    'ready с future due_at' => ['ready-due', ['due_at' => '2026-10-25 10:00:00+00'], '23514'],
    'blocked без reason' => ['blocked', ['state' => 'blocked'], '23514'],
    'failed_item без reason' => ['failed', ['state' => 'failed_item'], '23514'],
    'blocked handler_fault_limit без due' => ['fault-limit', ['state' => 'blocked', 'reason_code' => 'handler_fault_limit'], null],
    'provider_result_unknown без reconciliation due' => ['unknown', ['state' => 'blocked', 'reason_code' => 'provider_result_unknown'], '23514'],
    'provider_result_unknown с reconciliation due' => ['unknown-ok', ['state' => 'blocked', 'reason_code' => 'provider_result_unknown', 'provider_recovery_due_at' => '2026-10-25 10:00:30+00'], null],
    'provider_result_unknown с business due' => ['unknown-due', ['state' => 'blocked', 'reason_code' => 'provider_result_unknown', 'provider_recovery_due_at' => '2026-10-25 10:00:30+00', 'due_at' => '2026-10-25 10:00:30+00'], '23514'],
    'reconciliation due вне provider_result_unknown' => ['paused-due', ['state' => 'blocked', 'reason_code' => 'paused', 'provider_recovery_due_at' => '2026-10-25 10:00:30+00'], '23514'],
]);

test('nullable JSON/version pairs, положительные schema versions и counters закрыты CHECK', function (string $case, array $overrides, ?string $expected): void {
    $subject = LedgerFixtures::crawlBlock();

    expect(LedgerFixtures::sqlState(fn () => LedgerFixtures::insertRawWork($subject['block'], $overrides)))->toBe($expected, $case);
})->with([
    'checkpoint без версии' => ['checkpoint', ['checkpoint' => '{}'], '23514'],
    'версия без checkpoint' => ['version', ['checkpoint_schema_version' => 1], '23514'],
    'checkpoint pair' => ['pair', ['checkpoint' => '{}', 'checkpoint_schema_version' => 1], null],
    'checkpoint version 0' => ['zero', ['checkpoint' => '{}', 'checkpoint_schema_version' => 0], '23514'],
    'input schema version 0' => ['input-zero', ['input_schema_version' => 0], '23514'],
    'item_failures 3 — граница budget' => ['item-3', ['item_failures' => 3], null],
    'item_failures 4' => ['item-4', ['item_failures' => 4], '23514'],
    'отрицательный transport_attempts' => ['transport', ['transport_attempts' => -1], '23514'],
    'отрицательный generation' => ['generation', ['generation' => -1], '23514'],
    'work_key не SHA-256' => ['key', ['work_key' => new Expression("decode('00', 'hex')")], '23514'],
]);

test('остальные null pairs и entity CHECK journal, crawl block и handler hold', function (): void {
    $subject = LedgerFixtures::crawlBlock();
    $block = fn (array $overrides): Closure => fn () => DB::table('prc_crawl_blocks')->insert([
        'id' => LedgerFixtures::uuid()->value, 'crawl_run_id' => $subject['crawl_run']->value, 'block_key' => (string) Str::uuid(),
        'partition_payload' => '{}', 'partition_schema_version' => 1, 'filter_hash' => DB::raw("decode('".$subject['filter_hash']->hex."', 'hex')"), ...$overrides,
    ]);
    $transition = fn (array $overrides): Closure => fn () => DB::table('prc_process_transitions')->insert([
        'event_id' => LedgerFixtures::uuid()->value, 'entity_kind' => 'run', 'run_id' => $subject['run']->value, 'entity_seq' => 99,
        'new_state' => 'collecting', 'actor_kind' => 'system', 'schema_version' => 1, ...$overrides,
    ]);
    $hold = fn (array $overrides): Closure => fn () => DB::table('prc_process_handler_holds')->insert([
        'id' => LedgerFixtures::uuid()->value, 'stage' => 'listing', 'handler_version' => (string) Str::uuid(), ...$overrides,
    ]);

    expect(LedgerFixtures::sqlState($block(['terminal_proof' => '{}'])))->toBe('23514')
        ->and(LedgerFixtures::sqlState($block(['terminal_proof' => '{}', 'terminal_proof_schema_version' => 1])))->toBeNull()
        ->and(LedgerFixtures::sqlState($block(['head_generation' => 0])))->toBe('23514')
        ->and(LedgerFixtures::sqlState($transition(['details' => '{}'])))->toBe('23514')
        ->and(LedgerFixtures::sqlState($transition([])))->toBeNull()
        // Ровно один entity FK и он соответствует entity_kind; seq уникален внутри entity.
        ->and(LedgerFixtures::sqlState($transition(['entity_kind' => 'work'])))->toBe('23514')
        ->and(LedgerFixtures::sqlState($transition(['entity_seq' => 100, 'control_id' => null, 'incident_id' => null, 'work_id' => LedgerFixtures::insertRawWork($subject['block'])->value])))->toBe('23514')
        ->and(LedgerFixtures::sqlState($transition([])))->toBe('23505')
        ->and(LedgerFixtures::sqlState($hold([])))->toBeNull()
        ->and(LedgerFixtures::sqlState($hold(['state' => 'held'])))->toBe('23514')
        ->and(LedgerFixtures::sqlState($hold(['stage' => 'prepare'])))->toBe('23514');
});

test('discovery_outcome только collecting/complete/incomplete с согласованными closed_at и reason', function (string $case, string $outcome, bool $closed, ?string $reason, ?string $expected): void {
    $run = LedgerFixtures::crawlBlock()['run'];

    expect(LedgerFixtures::sqlState(fn () => DB::table('prc_process_runs')->where('id', $run->value)->update([
        'discovery_outcome' => $outcome,
        'discovery_closed_at' => $closed ? DB::raw('clock_timestamp()') : null,
        'discovery_reason_code' => $reason,
    ])))->toBe($expected, $case);
})->with([
    'collecting открыт' => ['collecting', 'collecting', false, null, null],
    'collecting закрыт' => ['collecting-closed', 'collecting', true, null, '23514'],
    'collecting с reason' => ['collecting-reason', 'collecting', false, 'cancelled', '23514'],
    'complete закрыт' => ['complete', 'complete', true, null, null],
    'complete без closed_at' => ['complete-open', 'complete', false, null, '23514'],
    'complete с reason' => ['complete-reason', 'complete', true, 'cancelled', '23514'],
    'incomplete с reason' => ['incomplete', 'incomplete', true, 'cancelled', null],
    'incomplete без reason' => ['incomplete-no-reason', 'incomplete', true, null, '23514'],
    'cancelled не outcome' => ['cancelled', 'cancelled', true, 'cancelled', '23514'],
]);

test('partial unique: один active intent на subject, один active admission group на work и composition CASCADE members', function (): void {
    $subject = LedgerFixtures::crawlBlock();
    $intent = fn (string $state): Closure => fn () => DB::table('prc_operation_intents')->insert([
        'id' => LedgerFixtures::uuid()->value, 'intent_key' => DB::raw("decode('".LedgerFixtures::hash((string) Str::uuid())->hex."', 'hex')"),
        'stage' => 'listing', 'subject_kind' => 'prc_crawl_blocks', 'subject_id' => $subject['block']->value,
        'semantic_policy_hash' => DB::raw("decode('".LedgerFixtures::hash('s')->hex."', 'hex')"), 'access_policy_hash' => DB::raw("decode('".LedgerFixtures::hash('a')->hex."', 'hex')"),
        'expected_head_generation' => 1, 'state' => $state, 'requested_at' => DB::raw('clock_timestamp()'),
    ]);
    expect(LedgerFixtures::sqlState($intent('active')))->toBeNull()
        ->and(LedgerFixtures::sqlState($intent('pending')))->toBeNull()
        ->and(LedgerFixtures::sqlState($intent('active')))->toBe('23505');

    $workId = LedgerFixtures::insertRawWork($subject['block'], ['state' => 'admitted', 'dispatch_claim_until' => '2026-10-25 10:00:00+00', 'dispatch_generation' => 1]);
    $laneId = LedgerFixtures::uuid()->value;
    DB::table('prc_process_lanes')->insert(['id' => $laneId, 'lane_key' => 'acquisition.listing.fixture', 'max_outstanding' => 10, 'max_running' => 2, 'low_watermark' => 5, 'refill_max' => 5]);
    $group = fn (string $state): Closure => fn () => DB::table('prc_admission_groups')->insert([
        'id' => LedgerFixtures::uuid()->value, 'work_id' => $workId->value, 'dispatch_generation' => 1,
        'required_lane_set_hash' => DB::raw("decode('".LedgerFixtures::hash('lanes')->hex."', 'hex')"), 'state' => $state, 'admitted_at' => DB::raw('clock_timestamp()'),
    ]);
    $groupId = LedgerFixtures::uuid()->value;
    DB::table('prc_admission_groups')->insert([
        'id' => $groupId, 'work_id' => $workId->value, 'dispatch_generation' => 1,
        'required_lane_set_hash' => DB::raw("decode('".LedgerFixtures::hash('lanes')->hex."', 'hex')"), 'state' => 'admitted', 'admitted_at' => DB::raw('clock_timestamp()'),
    ]);
    DB::table('prc_process_reservations')->insert(['admission_group_id' => $groupId, 'lane_id' => $laneId, 'work_id' => $workId->value, 'stage' => 'listing', 'state' => 'admitted']);

    expect(LedgerFixtures::sqlState($group('admitted')))->toBe('23505')
        ->and(LedgerFixtures::sqlState($group('released')))->toBeNull()
        // W > C обязателен, lane не теряет member независимо: удаление group удаляет ровно её members.
        ->and(LedgerFixtures::sqlState(fn () => DB::table('prc_process_lanes')->insert(['id' => LedgerFixtures::uuid()->value, 'lane_key' => 'bad', 'max_outstanding' => 2, 'max_running' => 3, 'low_watermark' => 1, 'refill_max' => 1])))->toBe('23514')
        ->and(LedgerFixtures::sqlState(fn () => DB::table('prc_process_work')->where('id', $workId->value)->delete()))->toBe('23503');

    DB::table('prc_admission_groups')->where('id', $groupId)->delete();
    expect(DB::table('prc_process_reservations')->where('admission_group_id', $groupId)->count())->toBe(0);
});
