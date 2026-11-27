<?php

declare(strict_types=1);

use App\Dto\Procurement\Acquisition\Control\AcquisitionConfiguration;
use App\Dto\Procurement\Process\Primitives\VersionedPayload;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Services\Procurement\Proxyma\Catalog\BindingAdapter;
use App\Services\Procurement\Proxyma\Migration\HistoricalRequests;
use App\Services\Procurement\Proxyma\Migration\Inventory;
use Illuminate\Support\Facades\DB;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Health\HealthScope;
use Proxyma\Infrastructure\Database\HealthRepository;
use Proxyma\Infrastructure\Database\PackageSchema;
use Proxyma\Infrastructure\Eloquent\ImportedHealthSampleRecord;
use Tests\Support\ProcessCore\ProxymaUpgradeFixtures as U;

test('исходная закреплённая схема с данными обновляется только forward migrations без потери FK identity debt и истории', function (): void {
    U::baseline();
    expect(DB::getSchemaBuilder()->hasTable('prc_proxies'))->toBeTrue()
        ->and(DB::table('migrations')->count())->toBe(78)
        ->and(DB::getSchemaBuilder()->hasTable('prc_proxyma_endpoint_links'))->toBeFalse();
    $f = U::populate();
    $snapshot = ['profile_version' => 1, 'route_profile_id' => $f['crawler']->route_profile_id, 'route_policy_version' => 2,
        'kind' => 'fresh', 'initial_from' => '2026-01-01T00:00:00+00:00', 'through' => '2026-01-02T00:00:00+00:00'];
    DB::table('prc_process_runs')->insert(['id' => Uuid::random()->value, 'kind' => 'profile',
        'profile_id' => $f['crawler']->id, 'configuration_snapshot' => json_encode($snapshot), 'configuration_schema_version' => 1,
        'configuration_hash' => DB::raw("decode(repeat('ab',32),'hex')"), 'requested_at' => DB::raw('clock_timestamp()')]);
    $historyBefore = DB::table('prc_request_attempts')->selectRaw('to_jsonb(prc_request_attempts)::text as payload')->pluck('payload')->all();
    $budgetsBefore = DB::table('prc_network_budgets')->orderBy('scope_key')->get(['id', 'tat', 'send_tat', 'not_before', 'policy_version'])->all();
    $sourceCount = DB::table('prc_network_routes')->count();
    $financialBefore = DB::table('llm_budget_reservations')->selectRaw('to_jsonb(llm_budget_reservations)::text as payload')->pluck('payload')->all();
    U::forward();
    [$inventory, $catalog, $state, $id] = U::services();
    $report = $inventory->dryRun();
    expect($report['unmapped_ids']['proxy'])->toHaveCount(2)
        ->and(json_encode($report))->not->toContain('fixture:opaque-credential', 'proxy.fixture.test');
    $catalog->run($id, $f['map']);
    $state->run($id);
    $budget = DB::table(PackageSchema::tables()['budgets'])->first();
    DB::table(PackageSchema::tables()['quota_windows'])->insert(['tenant_key' => 'system', 'budget_id' => $budget->id,
        'starts_at' => '2026-11-11 00:00:00+00', 'ends_at' => '2026-11-12 00:00:00+00', 'window_kind' => 'day',
        'quota_limit' => 20, 'reserved' => 3, 'spent' => 7]);
    $catalog->run($id, $f['map']);
    $state->run($id);
    $t = PackageSchema::tables();
    expect(array_filter($inventory->dryRun()['unmapped_ids']))->toBe([])
        ->and(DB::table('prc_proxyma_binding_links')->where('crawler_id', $f['crawler']->id)->count())->toBe(4)
        ->and(DB::table('prc_proxyma_owner_links')->where('owner_id', $f['operation'])->value('operation_id'))->toBe($f['operation']);
    expect(DB::table($t['routes'])->count())->toBe($sourceCount)
        ->and(DB::table($t['routes'])->where('egress_key', 'nat:shared')->count())->toBe(2)
        ->and(DB::table($t['endpoints'])->where('credential_version', 7)->count())->toBe(2)
        ->and(DB::table($t['targets'])->where('id', $f['retired'])->value('retired_at'))->not->toBeNull()
        ->and(DB::table($t['quota_windows'])->where('window_kind', 'day')->sole()->reserved)->toBe(3)
        ->and(DB::table($t['quota_windows'])->where('window_kind', 'day')->sole()->spent)->toBe(7)
        ->and(DB::table('prc_proxies')->count())->toBe(2)
        ->and(DB::table('prc_proxyma_endpoint_links')->count())->toBe(2)
        ->and(DB::table($t['route_target_health'])->where('id', $f['health']->id)->sole()->consecutive_failures)->toBe(4)
        ->and(DB::table((new ImportedHealthSampleRecord)->getTable())->count())->toBe(2)
        ->and(DB::table((new ImportedHealthSampleRecord)->getTable())->where('reducer_allowed', true)->count())->toBe(1)
        ->and(DB::table('prc_observations')->where('kind', 'adapter_validation')->count())->toBe(1);
    expect(DB::table('prc_adapter_health')->where('state', 'held')->sole()->health_epoch)->toBe(11)
        ->and(DB::table('llm_provider_calls')->where('physical_call_id', $f['request'])->exists())->toBeTrue()
        ->and(DB::table('llm_budget_reservations')->selectRaw('to_jsonb(llm_budget_reservations)::text as payload')->pluck('payload')->all())->toBe($financialBefore);
    foreach ($budgetsBefore as $old) {
        $new = DB::table($t['budgets'])->where('id', $old->id)->sole();
        expect([$new->tat, $new->send_tat, $new->cooldown_until, $new->revision])->toBe([$old->tat, $old->send_tat, $old->not_before, $old->policy_version]);
    }
    app()->forgetScopedInstances();
    $old = AcquisitionConfiguration::decode(new VersionedPayload(json_encode($snapshot), 1));
    expect(app(BindingAdapter::class)->snapshotProfile($old, StageCode::Detail))
        ->toBe(['profile_id' => $f['crawler']->route_profile_id, 'profile_version' => 2]);
    $unmapped = AcquisitionConfiguration::decode(new VersionedPayload(json_encode([
        ...$snapshot, 'route_policy_version' => 999,
    ], JSON_THROW_ON_ERROR), 1));
    expect(fn () => app(BindingAdapter::class)->snapshotProfile($unmapped, StageCode::Detail))
        ->toThrow(DomainException::class, 'Historical snapshot profile revision mismatch.');
    expect(DB::table('prc_request_attempts')->selectRaw('to_jsonb(prc_request_attempts)::text as payload')->pluck('payload')->all())->toBe($historyBefore)
        ->and((new HistoricalRequests(DB::connection()))->page()[0]->operation_id)->toBe($f['operation'])
        ->and(DB::table('prc_proxyma_state_archive')->where('source_table', 'prc_network_permits')->sole()->mapped_state)->toBe('completed')
        ->and(DB::table('prc_network_authorities')->sole()->authority)->toBe('legacy');
    $foreign = DB::select("SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid='prc_storage_reservations'::regclass AND contype='f'");
    expect(implode(' ', array_column($foreign, 'definition')))->toContain('prc_outbound_operations', 'prc_process_attempts');
    $bad = DB::select("SELECT c.conname FROM pg_constraint c JOIN pg_class t ON t.oid=c.conrelid JOIN pg_class p ON p.oid=c.confrelid
        WHERE c.contype='f' AND t.relname LIKE 'pxm\_%' AND p.relname LIKE 'prc\_%'");
    expect($bad)->toBe([]);
    $repository = app(HealthRepository::class);
    $window = new ReflectionMethod($repository, 'window');
    expect($window->invoke($repository, HealthScope::RouteTarget,
        'route_target_health:'.$f['health']->id, 9, new DateTimeImmutable, 'system'))->toBe([1, 1, 1]);
});

test('cutover checkpoint отклоняет повреждённые значения вместо молчаливого восстановления', function (): void {
    expect(Inventory::checkpoint('{"catalog":"sealed","state":null}'))->toBe(['catalog' => 'sealed', 'state' => null]);
    foreach ([null, 'null', '[]', '{"state":[]}', '{"catalog":7}'] as $invalid) {
        expect(fn () => Inventory::checkpoint($invalid))->toThrow(DomainException::class);
    }
    expect(fn () => Inventory::checkpoint('{"state":'))->toThrow(JsonException::class);
});
