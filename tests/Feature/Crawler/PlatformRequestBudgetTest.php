<?php

declare(strict_types=1);

use App\Dto\Procurement\Network\NetworkPermitWait;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\TransferBudget;
use App\Dto\Procurement\Network\TransportSucceeded;
use App\Dto\Procurement\Platform\OriginBudgetPolicy;
use App\Dto\Procurement\Platform\SourceTargetDefinition;
use App\Dto\Procurement\Platform\SourceTargetDefinitions;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Network\TrafficClass;
use App\Models\Procurement\Crawler\Proxy;
use App\Services\Procurement\Network\OutboundExecutor;
use App\Services\Procurement\Network\SourceCatalogSync;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\Http\PermitFixtures as F;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\LedgerFixtures;

beforeEach(function (): void {
    F::clean();
    $this->wire = new WireFixture;
    $this->f = new F($this->wire);
});
afterEach(function (): void {
    $this->wire->stop();
    F::clean();
});

function executeBudgetTraffic(F $f, WireFixture $wire, OutboundRequestPlan $plan): void
{
    $result = app(OutboundExecutor::class)->execute($plan, $f->context(), $f->gate(), $wire->policy(), new TransferBudget(65536, 65536));
    expect($result)->toBeInstanceOf(TransportSucceeded::class);
}

test('листинг и документы одного хоста делят окно, второй потребитель ждёт слот', function (): void {
    DB::table('prc_network_budgets')->update(['emission_interval_us' => 500000, 'max_inflight' => 2, 'burst' => 1]);
    executeBudgetTraffic($this->f, $this->wire, $this->f->plan(TrafficClass::FreshListing));
    executeBudgetTraffic($this->f, $this->wire, $this->f->plan(TrafficClass::Download));
    $delta = DB::selectOne('SELECT extract(epoch FROM max(authorized_at) - min(authorized_at))*1000000 AS us FROM prc_network_permits');
    expect((float) $delta->us)->toBeGreaterThanOrEqual(500000)
        ->and(DB::table('prc_network_demands')->distinct()->pluck('origin_budget_id')->all())->toHaveCount(1)
        ->and(DB::table('prc_network_demands')->orderBy('ticket_seq')->pluck('traffic_class')->all())->toBe(['fresh_listing', 'download'])
        ->and($this->wire->receipts())->toHaveCount(2);
});

test('ёмкость растёт только от активных привязанных узлов площадки', function (): void {
    $active = Proxy::factory()->create();
    $inactive = Proxy::factory()->create();
    DB::table('prc_network_routes')->where('id', $inactive->route_id)->update(['enabled' => false]);
    $default = DB::table('prc_network_route_profiles')->where('profile_key', 'default')->sole();
    DB::table('prc_network_route_profile_members')->insert(['profile_id' => $default->id, 'route_id' => $active->route_id]);
    DB::table('prc_network_budgets')->update(['max_inflight' => 1]);
    $one = $this->f->context();
    $two = $this->f->context();
    $this->f->reserve($one);
    $waiting = $this->f->repo->reserve($this->f->ticket($two), $two);
    expect($waiting)->toBeInstanceOf(NetworkPermitWait::class)->and($waiting->reason->value)->toBe('capacity_wait')
        ->and(DB::table('prc_network_permits')->where('state', 'reserved')->count())->toBe(1)
        ->and(DB::table('prc_network_budgets')->where('scope_key', $this->f->url->origin->budgetScopeKey())->sole()->max_inflight)->toBe(1)
        ->and($this->wire->receipts())->toBe([]);
});

test('стадийная пауза берётся из резолвера площадки и входит в измеренное ожидание', function (): void {
    DB::table('prc_network_budgets')->update(['emission_interval_us' => 321000, 'max_inflight' => 2, 'burst' => 1]);
    $start = hrtime(true);
    executeBudgetTraffic($this->f, $this->wire, $this->f->plan(TrafficClass::FreshListing));
    executeBudgetTraffic($this->f, $this->wire, $this->f->plan(TrafficClass::FreshListing));
    $delta = DB::selectOne('SELECT extract(epoch FROM max(authorized_at) - min(authorized_at))*1000000 AS us FROM prc_network_permits');
    expect((float) $delta->us)->toBeGreaterThanOrEqual(321000)
        ->and((hrtime(true) - $start) / 1000000)->toBeGreaterThanOrEqual(321)
        ->and($this->wire->receipts())->toHaveCount(2);
});

test('разные хосты не расходуют общий счётчик', function (): void {
    $f = $this->f;
    $other = $this->wire->url('/ok', host: 'second.test');
    app(SourceCatalogSync::class)->sync(new SourceTargetDefinitions($f->code, [
        new SourceTargetDefinition('card', 'detail', $f->url->origin, [$f->profile], new OriginBudgetPolicy(600, 1, 2)),
        new SourceTargetDefinition('other', 'detail', $other->origin, [$f->profile], new OriginBudgetPolicy(600, 1, 2)),
    ]));
    foreach (['prc_route_health', 'prc_route_target_health', 'prc_target_health'] as $table) {
        DB::table($table)->update(['state' => 'closed']);
    }
    executeBudgetTraffic($f, $this->wire, $f->plan());
    $before = DB::table('prc_network_budgets')->where('scope_key', $f->url->origin->budgetScopeKey())->sole()->tat;
    $target = DB::table('prc_source_targets')->where('target_key', 'other')->sole();
    $original = $f->plan();
    $plan = new OutboundRequestPlan(LedgerFixtures::uuid(), new ProcessUuid($target->id), 'GET', $other, $f->profile,
        TrafficClass::Detail, $original->idempotency);
    executeBudgetTraffic($f, $this->wire, $plan);
    expect(DB::table('prc_network_budgets')->where('scope_key', $f->url->origin->budgetScopeKey())->sole()->tat)->toBe($before)
        ->and(DB::table('prc_network_demands')->distinct()->pluck('origin_budget_id')->all())->toHaveCount(2)
        ->and($this->wire->receipts())->toHaveCount(2);
});
