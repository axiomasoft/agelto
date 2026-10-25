<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\ScopeDefinition;
use App\Enums\Procurement\Process\ControlMode;
use App\Enums\Procurement\Process\HandlerEligibility;
use App\Enums\Procurement\Process\ScopeKind;
use App\Enums\Procurement\Process\StageCode;
use App\Models\Procurement\Process\ControlScope;
use App\Models\Procurement\Process\Lane;
use App\Repositories\Procurement\Process\ControlRepository;
use App\Services\Procurement\Process\ProcessAuthoritySync;
use App\Services\Procurement\Process\ProcessPolicy;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('t01', 'p16');

/** Policy из versioned config/process.php после явной мутации массива (lane keys содержат точки). */
function processPolicyWith(Closure $mutate): ProcessPolicy
{
    return new ProcessPolicy(new Repository(['process' => $mutate(require config_path('process.php'))]));
}

/** @param  array<string, mixed>  $replacement */
function processPolicyReplace(array $replacement): Closure
{
    return fn (array $process): array => array_replace_recursive($process, $replacement);
}

test('policy v1 задаёт стартовые W/C lanes, finite required lanes и handler fault budget 3', function (): void {
    $policy = app(ProcessPolicy::class);
    $lanes = collect($policy->lanes())->mapWithKeys(fn ($lane): array => [$lane->key => [$lane->parentKey, $lane->maxOutstanding, $lane->maxRunning, $lane->lowWatermark, $lane->refillMax]]);

    expect($policy->policyVersion)->toBe(1)
        ->and($policy->maxHandlerFaultsPerWorkVersion)->toBe(3)
        ->and($lanes->all())->toBe([
            'acquisition' => [null, 20, 4, 10, 10],
            'analysis' => [null, 20, 10, 10, 10],
            'download' => [null, 20, 4, 10, 10],
            'prepare' => [null, 10, 2, 5, 5],
            'publication' => [null, 10, 2, 5, 5],
            'acquisition.detail' => ['acquisition', 20, 4, 10, 10],
            'acquisition.listing' => ['acquisition', 10, 2, 5, 5],
            'acquisition.status_refresh' => ['acquisition', 10, 2, 5, 5],
            'analysis.extraction' => ['analysis', 20, 10, 10, 10],
            'analysis.screening' => ['analysis', 20, 10, 10, 10],
        ])
        ->and($policy->requiredLanes(StageCode::Listing))->toBe(['acquisition', 'acquisition.listing'])
        ->and($policy->requiredLanes(StageCode::Download))->toBe(['download'])
        ->and($policy->requiredLanes(StageCode::Screening))->toBe(['analysis', 'analysis.screening'])
        ->and(array_map(fn (ScopeDefinition $scope): string => $scope->key, $policy->stageScopes(StageCode::StatusRefresh)))
        ->toBe(['system', 'acquisition', 'acquisition:stage:status_refresh'])
        ->and(array_map(fn (ScopeDefinition $scope): string => $scope->key, $policy->stageScopes(StageCode::Download)))
        ->toBe(['system', 'documents', 'documents:stage:download']);
});

test('невалидная policy отвергается с точным config key, а не заменяется default', function (Closure $mutate, string $key): void {
    expect(fn () => processPolicyWith($mutate))->toThrow(InvalidArgumentException::class, 'Invalid process policy: '.$key);
})->with([
    'нулевой handler fault budget' => [processPolicyReplace(['max_handler_faults_per_work_version' => 0]), 'process.max_handler_faults_per_work_version'],
    'строковый handler fault budget' => [processPolicyReplace(['max_handler_faults_per_work_version' => '3']), 'process.max_handler_faults_per_work_version'],
    'C больше W' => [processPolicyReplace(['lanes' => ['download' => ['max_running' => 21]]]), 'process.lanes.download'],
    'low watermark равен W' => [processPolicyReplace(['lanes' => ['prepare' => ['low_watermark' => 10]]]), 'process.lanes.prepare'],
    'refill больше W' => [processPolicyReplace(['lanes' => ['publication' => ['refill_max' => 11]]]), 'process.lanes.publication'],
    'неизвестный parent' => [processPolicyReplace(['lanes' => ['acquisition.listing' => ['parent' => 'missing']]]), 'process.lanes.acquisition.listing.parent'],
    'вложенный parent глубже одного уровня' => [processPolicyReplace(['lanes' => ['analysis.screening' => ['parent' => 'acquisition.listing']]]), 'process.lanes.analysis.screening.parent'],
    'отсутствует required lane' => [function (array $process): array {
        unset($process['lanes']['analysis.screening']);

        return $process;
    }, 'process.lanes.analysis.screening'],
    'лишнее поле lane' => [processPolicyReplace(['lanes' => ['download' => ['ttl' => 30]]]), 'process.lanes.download'],
    'нулевая policy version' => [processPolicyReplace(['policy_version' => 0]), 'process.policy_version'],
]);

test('ensureBaseline материализует scopes и lanes только schema-supported stages и идемпотентен', function (): void {
    $sync = app(ProcessAuthoritySync::class);
    $sync->ensureBaseline();
    $first = [
        DB::table('prc_control_scopes')->orderBy('scope_key')->get()->all(),
        DB::table('prc_process_lanes')->orderBy('lane_key')->get()->all(),
    ];
    $sync->ensureBaseline();

    $scopes = ControlScope::query()->orderBy('scope_key')->get()->keyBy('scope_key');
    $lanes = Lane::query()->get()->keyBy('lane_key');

    expect($scopes->keys()->sort()->values()->all())->toBe([
        'acquisition', 'acquisition:stage:detail', 'acquisition:stage:listing', 'acquisition:stage:status_refresh',
        'documents', 'documents:stage:download', 'system',
    ])
        ->and($scopes['system']->parent_id)->toBeNull()
        ->and($scopes['acquisition']->parent_id)->toBe($scopes['system']->id)
        ->and($scopes['acquisition:stage:listing']->parent_id)->toBe($scopes['acquisition']->id)
        ->and($scopes['documents:stage:download']->scope_kind)->toBe(ScopeKind::Stage)
        ->and($scopes['documents:stage:download']->parent_id)->toBe($scopes['documents']->id)
        // Default-enabled — состояние существующей authority row, а не отсутствие row.
        ->and($scopes->every(fn (ControlScope $scope): bool => $scope->desired_mode === ControlMode::Enabled && $scope->control_version === 1))->toBeTrue()
        // Lanes будущих stages (prepare/analysis/publication) не создаются заранее.
        ->and($lanes->keys()->sort()->values()->all())->toBe(['acquisition', 'acquisition.detail', 'acquisition.listing', 'acquisition.status_refresh', 'download'])
        ->and($lanes['acquisition.listing']->parent_lane_id)->toBe($lanes['acquisition']->id)
        ->and([$lanes['acquisition.listing']->max_outstanding, $lanes['acquisition.listing']->max_running])->toBe([10, 2])
        ->and([
            DB::table('prc_control_scopes')->orderBy('scope_key')->get()->all(),
            DB::table('prc_process_lanes')->orderBy('lane_key')->get()->all(),
        ])->toEqual($first)
        // Authority rows не являются work/journal: sync не пишет ledger.
        ->and(DB::table('prc_process_work')->count() + DB::table('prc_process_transitions')->count() + DB::table('prc_process_outbox')->count())->toBe(0);
});

test('policy change обновляет limits и version, но не сбрасывает manual mode, fairness cursor и topology', function (): void {
    app(ProcessAuthoritySync::class)->ensureBaseline();
    DB::table('prc_control_scopes')->where('scope_key', 'acquisition')->update(['desired_mode' => 'paused', 'control_version' => 2]);
    DB::table('prc_process_lanes')->where('lane_key', 'download')->update(['fairness_cursor' => '{"last_cohort":"manual"}']);
    $policy = processPolicyWith(processPolicyReplace(['policy_version' => 2, 'lanes' => ['download' => ['max_outstanding' => 30, 'max_running' => 6]]]));
    app()->instance(ProcessPolicy::class, $policy);

    app(ProcessAuthoritySync::class)->ensureBaseline();
    $download = Lane::query()->where('lane_key', 'download')->sole();
    $listing = Lane::query()->where('lane_key', 'acquisition.listing')->sole();

    expect([$download->max_outstanding, $download->max_running, $download->policy_version])->toBe([30, 6, 2])
        ->and($download->fairness_cursor)->toBe(['last_cohort' => 'manual'])
        ->and($listing->policy_version)->toBe(2)
        ->and(ControlScope::query()->where('scope_key', 'acquisition')->sole()->desired_mode)->toBe(ControlMode::Paused)
        ->and(ControlScope::query()->where('scope_key', 'acquisition')->sole()->control_version)->toBe(2);

    DB::table('prc_process_lanes')->where('lane_key', 'acquisition.listing')->update(['parent_lane_id' => $download->id]);
    expect(fn () => app(ProcessAuthoritySync::class)->ensureBaseline())->toThrow(LogicException::class, 'Lane topology is immutable: acquisition.listing');
});

test('ensureScopes требует полную цепочку, не зависит от порядка входа и отвергает смену topology', function (): void {
    $repository = app(ControlRepository::class);
    $platform = [
        new ScopeDefinition(ScopeKind::System, 'system', null),
        new ScopeDefinition(ScopeKind::Acquisition, 'acquisition', 'system'),
        new ScopeDefinition(ScopeKind::Platform, 'acquisition:platform:fixture', 'acquisition'),
    ];

    expect(fn () => DB::transaction(fn () => $repository->ensureScopes([$platform[2]])))
        ->toThrow(InvalidArgumentException::class, 'every ancestor')
        ->and(fn () => new ScopeDefinition(ScopeKind::Platform, 'orphan', null))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ScopeDefinition(ScopeKind::System, 'system', 'system'))->toThrow(InvalidArgumentException::class)
        ->and(DB::table('prc_control_scopes')->count())->toBe(0);

    // Порядок входа не важен: reversed цепочка даёт те же rows.
    DB::transaction(fn () => $repository->ensureScopes(array_reverse($platform)));
    DB::transaction(fn () => $repository->ensureScopes($platform));
    expect(DB::table('prc_control_scopes')->count())->toBe(3)
        ->and(fn () => DB::transaction(fn () => $repository->ensureScopes([
            new ScopeDefinition(ScopeKind::System, 'system', null),
            new ScopeDefinition(ScopeKind::Documents, 'documents', 'system'),
            new ScopeDefinition(ScopeKind::Platform, 'acquisition:platform:fixture', 'documents'),
        ])))->toThrow(LogicException::class, 'Control scope topology is immutable: acquisition:platform:fixture')
        ->and(DB::table('prc_control_scopes')->count())->toBe(3);
});

test('handler registration материализует healthy row, повтор не снимает held, новая версия — своя row', function (): void {
    $sync = app(ProcessAuthoritySync::class);
    $healthy = $sync->registerHandler(StageCode::Listing, 'fixture-listing/1');

    expect($healthy->eligibility)->toBe(HandlerEligibility::Healthy)->and($healthy->version)->toBe(1);

    $incident = LedgerFixtures::uuid()->value;
    DB::table('prc_process_incidents')->insert([
        'id' => $incident, 'fingerprint' => DB::raw("decode('".LedgerFixtures::hash('hold')->hex."', 'hex')"), 'scope_key' => 'acquisition:stage:listing',
        'kind' => 'handler_hold', 'state' => 'open', 'first_seen_at' => DB::raw('clock_timestamp()'), 'last_seen_at' => DB::raw('clock_timestamp()'),
        'evidence' => '{}', 'evidence_schema_version' => 1,
    ]);
    DB::table('prc_process_handler_holds')->where('id', $healthy->id->value)->update([
        'state' => 'held', 'version' => 2, 'fault_signature' => DB::raw("decode('".LedgerFixtures::hash('signature')->hex."', 'hex')"),
        'incident_id' => $incident, 'held_at' => DB::raw('clock_timestamp()'),
    ]);

    $again = $sync->registerHandler(StageCode::Listing, 'fixture-listing/1');
    $next = $sync->registerHandler(StageCode::Listing, 'fixture-listing/2');

    expect($again->id)->toEqual($healthy->id)
        ->and($again->eligibility)->toBe(HandlerEligibility::Held)
        ->and($again->version)->toBe(2)
        ->and($next->eligibility)->toBe(HandlerEligibility::Healthy)
        ->and($next->id)->not->toEqual($healthy->id)
        ->and(DB::table('prc_process_handler_holds')->where('handler_version', 'fixture-listing/1')->value('incident_id'))->toBe($incident)
        // Stage без schema owner tuple не получает authority row.
        ->and(fn () => $sync->registerHandler(StageCode::Extraction, 'fixture-extraction/1'))->toThrow(InvalidArgumentException::class, 'not schema-supported')
        ->and(DB::table('prc_process_handler_holds')->count())->toBe(2);
});
