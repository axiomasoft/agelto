<?php

declare(strict_types=1);

use App\Dto\Procurement\Network\ScheduledNetworkPermit;
use Illuminate\Support\Facades\DB;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\Proxy\HealthFixtures;

// Exact historical names identify the original scenarios. Canonical projections replace
// the retired auto-disable/EWMA model; their explicit mapping is in scenario-map.json.
beforeEach(function (): void {
    PermitFixtures::clean();
    config(['outbound.health.global_consecutive' => 2]);
    $this->wire = new WireFixture;
    $this->health = new HealthFixtures($this->wire);
    $this->proxy = $this->health->proxy();
    DB::table('prc_network_budgets')->update(['max_inflight' => 2, 'emission_interval_us' => 1]);
    $this->first = $this->health->authorize($this->health->permits->context(), $this->proxy);
    $this->second = $this->health->authorize($this->health->permits->context(), $this->proxy);
});

afterEach(function (): void {
    $this->wire->stop();
    PermitFixtures::clean();
});

test('два одновременных глобальных отказа учитываются оба и один раз отключают узел', function (): void {
    $workers = ConcurrentTransactions::concurrently([
        'first' => canonicalProxyOutcomeWorker($this->first, 'ProxyConnect'),
        'second' => canonicalProxyOutcomeWorker($this->second, 'ProxyConnect'),
    ]);
    $row = DB::table('prc_route_health')->where('route_id', $this->proxy->id->value)->sole();

    expect($workers['first']['backend_pid'])->not->toBe($workers['second']['backend_pid'])
        ->and(DB::table('prc_observations')->where('kind', 'network_transport')->where('stale', false)->count())->toBe(2)
        ->and($row->state)->toBe('open')->and($row->recent_openings)->toBe(1)
        ->and($row->consecutive_failures)->toBe(0)
        ->and(DB::table('prc_network_routes')->where('id', $this->proxy->id->value)->value('enabled'))->toBeTrue()
        ->and(DB::table('prc_network_permits')->where('state', 'released')->count())->toBe(2);
});

test('два одновременных успешных замера входят в EWMA при alpha 0.3', function (): void {
    $workers = ConcurrentTransactions::concurrently([
        'first' => canonicalProxyOutcomeWorker($this->first),
        'second' => canonicalProxyOutcomeWorker($this->second),
    ]);
    $row = DB::table('prc_route_health')->where('route_id', $this->proxy->id->value)->sole();

    expect($workers['first']['backend_pid'])->not->toBe($workers['second']['backend_pid'])
        ->and(DB::table('prc_observations')->where('kind', 'network_transport')->where('stale', false)->count())->toBe(2)
        ->and(DB::table('prc_observation_applications')->where('projection_key', 'prc_route_health:'.$row->id)->count())->toBe(2)
        ->and($row->state_version)->toBe(3)->and($row->consecutive_failures)->toBe(0)
        ->and($row->last_success_at)->not->toBeNull();
});

test('смешанные global и target outcomes не инвертируют порядок блокировок', function (): void {
    $workers = ConcurrentTransactions::concurrently([
        'global' => canonicalProxyOutcomeWorker($this->first, 'ProxyConnect'),
        'target' => canonicalProxyOutcomeWorker($this->second, 'Timeout'),
    ]);

    expect($workers['global']['backend_pid'])->not->toBe($workers['target']['backend_pid'])
        ->and(DB::table('prc_route_health')->where('route_id', $this->proxy->id->value)->value('consecutive_failures'))->toBe(1)
        ->and(DB::table('prc_route_target_health')->where('route_id', $this->proxy->id->value)->value('consecutive_failures'))->toBe(1)
        ->and(DB::table('prc_observations')->where('kind', 'network_transport')->where('stale', false)->count())->toBe(2)
        ->and(DB::table('prc_route_health')->where('route_id', $this->proxy->id->value)->value('state'))->toBe('closed');
});

function canonicalProxyOutcomeWorker(ScheduledNetworkPermit $permit, ?string $failure = null): string
{
    $request = var_export($permit->ticket->plan->physicalRequestId->value, true);
    $outcome = $failure === null
        ? '\\Tests\\Support\\ProcessCore\\Proxy\\HealthFixtures::success()'
        : '\\Tests\\Support\\ProcessCore\\Proxy\\HealthFixtures::failure(\\App\\Enums\\Procurement\\Network\\TransportFailure::'.$failure.')';

    return <<<PHP
config(['outbound.health.global_consecutive' => 2]);
app(\\App\\Repositories\\Procurement\\Network\\HealthRepository::class)->record(new \\App\\Dto\\Procurement\\Process\\ProcessUuid({$request}), {$outcome});
return ['backend_pid' => \\Illuminate\\Support\\Facades\\DB::scalar('select pg_backend_pid()')];
PHP;
}
