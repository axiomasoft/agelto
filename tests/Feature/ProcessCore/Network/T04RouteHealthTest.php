<?php

declare(strict_types=1);

use App\Dto\Procurement\Network\HttpRouteBinding;
use App\Dto\Procurement\Network\NetworkRouteSelection;
use App\Dto\Procurement\Network\ProbeClaim;
use App\Dto\Procurement\Network\RetryRoutePreference;
use App\Dto\Procurement\Network\TransferBudget;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Network\TransportSucceeded;
use App\Enums\Procurement\Network\NetworkBlockedReason;
use App\Enums\Procurement\Network\NetworkDeferralReason;
use App\Enums\Procurement\Network\OutboundOperationKind;
use App\Services\Procurement\Network\OutboundExecutor;
use App\Services\Procurement\Network\ProbeScheduler;
use App\Services\Procurement\Proxy\ProxyResolver;
use App\Services\Procurement\Proxy\RoutePolicy;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\Proxy\HealthFixtures as F;

beforeEach(function (): void {
    PermitFixtures::clean();
    $this->wire = new WireFixture;
    $this->f = new F($this->wire);
});
afterEach(function (): void {
    $this->wire->stop();
    PermitFixtures::clean();
});
pest()->group('t03', 't04', 'p24');

test('два ConnectionException затем200 сохраняют три request records одинsuccess ноль item failures с A B B rotation', function (): void {
    $f = $this->f;
    $a = new HttpRouteBinding($f->permits->route->routeId);
    $b = $f->proxy();
    $before = DB::table('prc_process_work')->sum('item_failures');
    $result = app(OutboundExecutor::class)->execute($f->permits->plan(url: $this->wire->url('/sequence')),
        $f->permits->context(), $f->gate([$a, $b]), $this->wire->policy(), new TransferBudget(1000000, 1000000));
    $rows = DB::table('prc_request_attempts')->orderBy('request_seq')->get();
    expect($result)->toBeInstanceOf(TransportSucceeded::class)->and($rows)->toHaveCount(3)
        ->and($rows->pluck('route_id')->all())->toBe([$a->id->value, $b->id->value, $b->id->value])
        ->and($rows->where('status', 200))->toHaveCount(1)
        ->and(array_filter($this->wire->receipts(), fn ($r) => $r['kind'] === 'http'))->toHaveCount(3)
        ->and(DB::table('prc_process_work')->sum('item_failures'))->toBe($before)
        ->and(DB::table('prc_route_health')->where('state', 'open')->count())->toBe(0)
        ->and(DB::table('prc_target_health')->sole()->state)->toBe('closed')
        ->and(DB::table('prc_target_health')->sole()->availability)->toBe('suspect')
        ->and(DB::table('prc_observations')->count())->toBe(3);
});

test('один timeout A и success B дают ровно2 requests без premature route deferral', function (): void {
    $f = $this->f;
    $a = $f->proxy($this->wire->ports['stall_proxy']);
    $b = $f->proxy();
    $result = app(OutboundExecutor::class)->execute($f->permits->plan(), $f->permits->context(), $f->gate([$a, $b]),
        $this->wire->policy(), new TransferBudget(1000000, 1000000));
    expect($result)->toBeInstanceOf(TransportSucceeded::class)
        ->and(DB::table('prc_request_attempts')->orderBy('request_seq')->pluck('route_id')->all())->toBe([$a->id->value, $b->id->value])
        ->and(DB::table('prc_route_health')->where('route_id', $a->id->value)->sole()->consecutive_failures)->toBe(0)
        ->and(DB::table('prc_route_target_health')->where('route_id', $a->id->value)->sole()->consecutive_failures)->toBe(1)
        ->and(DB::table('prc_target_health')->sole()->availability)->toBe('suspect');
});

test('407 holds exact credential version и разрешает fresh alternate proxy без повтора плохих credentials', function (): void {
    $f = $this->f;
    $a = $f->proxy($this->wire->ports['auth']);
    $b = $f->proxy();
    $result = app(OutboundExecutor::class)->execute($f->permits->plan(), $f->permits->context(), $f->gate([$a, $b]),
        $this->wire->policy(), new TransferBudget(1000000, 1000000));
    expect($result)->toBeInstanceOf(TransportSucceeded::class)
        ->and(DB::table('prc_request_attempts')->orderBy('request_seq')->pluck('route_id')->all())->toBe([$a->id->value, $b->id->value]);
    $row = DB::table('prc_route_health')->where('route_id', $a->id->value)->sole();
    expect($row->reason_code)->toBe('credential_hold')->and($row->open_until)->toBeNull();
    $selector = app(ProxyResolver::class)->outboundRoutes(new RoutePolicy([$a], $f->profile));
    $preference = new RetryRoutePreference([], null, false, 2);
    expect($selector->select($f->permits->plan(), $preference))->toBeInstanceOf(TransportBlocked::class);
    DB::table('prc_network_routes')->where('id', $a->id->value)->increment('credential_version');
    $due = $selector->select($f->permits->plan(), $preference);
    expect($due)->toBeInstanceOf(TransportDeferred::class)->and($due->reason)->toBe(NetworkDeferralReason::TargetCooldown);
    $repaired = new HttpRouteBinding($a->id, 'http://127.0.0.1:'.$this->wire->ports['proxy'], credentialVersion: 2);
    $context = $f->permits->context(kind: OutboundOperationKind::Probe, probeToken: LedgerFixtures::uuid());
    $claim = app(ProbeScheduler::class)->claim($f->permits->plan(), $context,
        new NetworkRouteSelection($a->id, $f->profile, 2));
    expect($claim)->toBeInstanceOf(ProbeClaim::class);
    $result = app(OutboundExecutor::class)->execute($f->permits->plan(), $context, $f->gate([$repaired], $claim),
        $this->wire->policy(), new TransferBudget(1000000, 1000000));
    expect($result)->toBeInstanceOf(TransportSucceeded::class)
        ->and(DB::table('prc_route_health')->where('route_id', $a->id->value)->sole()->state)->toBe('closed');
});

test('direct eligibility требует explicit identity healthy rows и enabled; success не включает admin disabled', function (): void {
    $f = $this->f;
    $route = $f->permits->route->routeId;
    $selector = app(ProxyResolver::class)->outboundRoutes(new RoutePolicy([new HttpRouteBinding($route)]));
    $preference = new RetryRoutePreference([], null, false, 2);
    expect($selector->select($f->permits->plan(), $preference)->selection->routeId->value)->toBe($route->value);
    $inflight = $f->authorize($f->permits->context());
    DB::table('prc_network_routes')->where('id', $route->value)->update(['enabled' => false]);
    $f->permits->repo->record($inflight->ticket->plan->physicalRequestId, F::success());
    expect(DB::table('prc_network_routes')->sole()->enabled)->toBeFalse()
        ->and($selector->select($f->permits->plan(), $preference)->reason)->toBe(NetworkDeferralReason::NoRoute);
});

test('429 не rotates и не изменяет proxy series а503 retry остаётся на текущем egress', function (): void {
    $f = $this->f;
    $a = new HttpRouteBinding($f->permits->route->routeId);
    $b = $f->proxy();
    $result = app(OutboundExecutor::class)->execute($f->permits->plan(url: $this->wire->url('/quota')),
        $f->permits->context(), $f->gate([$a, $b]), $this->wire->policy(), new TransferBudget(1000000, 1000000));
    expect($result->reason)->toBe(NetworkDeferralReason::RateLimit)->and(DB::table('prc_request_attempts')->count())->toBe(1)
        ->and(DB::table('prc_route_target_health')->sum('consecutive_failures'))->toBe(0);
    DB::table('prc_network_budgets')->update(['not_before' => '-infinity', 'tat' => '-infinity']);
    app(OutboundExecutor::class)->execute($f->permits->plan(url: $this->wire->url('/unavailable')),
        $f->permits->context(), $f->gate([$a]), $this->wire->policy(), new TransferBudget(1000000, 1000000));
    expect(DB::table('prc_request_attempts')->where('status', 503)->pluck('route_id')->unique()->all())->toBe([$a->id->value])
        ->and(DB::table('prc_route_target_health')->sum('consecutive_failures'))->toBe(0)
        ->and(DB::table('prc_target_health')->sole()->state)->toBe('closed');
});

test('неeligible profile никогда не falls back к здоровому direct или чужому pool', function (): void {
    $f = $this->f;
    $a = $f->proxy();
    DB::table('prc_network_routes')->where('id', $a->id->value)->update(['enabled' => false]);
    $result = app(OutboundExecutor::class)->execute($f->permits->plan(), $f->permits->context(), $f->gate([$a]),
        $this->wire->policy(), new TransferBudget(1000000, 1000000));
    expect($result)->toBeInstanceOf(TransportDeferred::class)->and($result->reason)->toBe(NetworkDeferralReason::NoRoute)
        ->and(DB::table('prc_request_attempts')->count())->toBe(0)->and($this->wire->receipts())->toHaveCount(0);
});

test('credential rotation между binding reserve и send не разрешает старый secret', function (): void {
    $f = $this->f;
    $proxy = $f->proxy();
    $context = $f->permits->context();
    $selection = new NetworkRouteSelection($proxy->id, $f->profile);
    $ticket = $f->permits->repo->register($f->permits->plan(), $context, $selection);
    $permit = $f->permits->repo->reserve($ticket, $context);
    DB::table('prc_network_routes')->where('id', $proxy->id->value)->increment('credential_version');
    expect($f->permits->repo->claimSend($permit, $context))->toBeInstanceOf(TransportBlocked::class)
        ->and(DB::table('prc_request_attempts')->count())->toBe(0)->and($this->wire->receipts())->toHaveCount(0);
    expect($f->permits->repo->register($f->permits->plan(), $context, $selection))->toBeInstanceOf(TransportBlocked::class);
});

test('missing materialized health is typed configuration hold до отправки', function (): void {
    DB::table('prc_route_target_health')->delete();
    $f = $this->f;
    $result = app(OutboundExecutor::class)->execute($f->permits->plan(), $f->permits->context(),
        $f->gate([new HttpRouteBinding($f->permits->route->routeId)]), $this->wire->policy(), new TransferBudget(1000000, 1000000));
    expect($result)->toBeInstanceOf(TransportBlocked::class)->and($result->reason)->toBe(NetworkBlockedReason::ConfigurationHold)
        ->and(DB::table('prc_request_attempts')->count())->toBe(0);
});
