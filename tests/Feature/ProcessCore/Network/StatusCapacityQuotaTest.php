<?php

declare(strict_types=1);

use App\Dto\Procurement\Network\TransferBudget;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Network\TransportSucceeded;
use App\Enums\Procurement\Network\NetworkDeferralReason;
use App\Enums\Procurement\Network\TrafficClass;
use App\Services\Procurement\Network\OutboundExecutor;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\Http\PermitFixtures as F;
use Tests\Support\ProcessCore\Http\WireFixture;

beforeEach(function (): void {
    F::clean();
    $this->wire = new WireFixture;
    $this->f = new F($this->wire);
});
afterEach(function (): void {
    $this->wire->stop();
    F::clean();
});
pest()->group('status-capacity-quota');

test('status-only имеет capacity quota 18/hour и считает каждый retry redirect один раз', function (string $path, int $physicalCount): void {
    $f = $this->f;
    $context = $f->context();
    $f->history($context, 18 - $physicalCount);
    DB::table('prc_network_budgets')->where('scope_key', $f->url->origin->budgetScopeKey())->update(['emission_interval_us' => 60_000_000, 'burst' => 3]);
    $outcome = app(OutboundExecutor::class)->execute($f->plan(TrafficClass::StatusRefresh, $this->wire->url($path)), $context, $f->gate(), $this->wire->policy(), new TransferBudget(1_000_000, 1_000_000));
    expect($outcome)->toBeInstanceOf(TransportSucceeded::class)->and($this->wire->receipts())->toHaveCount($physicalCount)
        ->and(DB::table('prc_request_attempts')->count())->toBe(18);
    $next = $f->context();
    $ticket = $f->ticket($next, $f->plan(TrafficClass::StatusRefresh));
    $debt = DB::table('prc_network_budgets')->orderBy('id')->pluck('tat')->all();
    $denied = $f->repo->reserve($ticket, $next);
    expect($denied)->toBeInstanceOf(TransportDeferred::class)->and($denied->reason)->toBe(NetworkDeferralReason::StatusRefreshBudget)
        ->and($denied->retryAt->value->getTimestamp())->toBeGreaterThan(time() + 3500)
        ->and(DB::table('prc_network_demands')->where('id', $ticket->id->value)->sole()->state)->toBe('cancelled')
        ->and(DB::table('prc_network_budgets')->orderBy('id')->pluck('tat')->all())->toBe($debt);
})->with([['/sequence', 3], ['/redirect', 2]]);

test('reserved future status занимает quota до expiry и rate version recheck не даёт oversend', function (): void {
    $f = $this->f;
    $c = $f->context();
    $f->history($c, 17);
    DB::table('prc_network_budgets')->where('scope_key', $f->url->origin->budgetScopeKey())->update(['emission_interval_us' => 60_000_000, 'burst' => 3]);
    $p = $f->reserve($c, $f->plan(TrafficClass::StatusRefresh));
    $other = $f->context();
    DB::table('prc_network_budgets')->where('scope_key', $f->url->origin->routeScopeKey($f->route->routeId->value))->update(['max_inflight' => 2]);
    $ticket = $f->ticket($other, $f->plan(TrafficClass::StatusRefresh));
    $result = $f->repo->reserve($ticket, $other);
    expect($result)->toBeInstanceOf(TransportDeferred::class)->and($result->reason)->toBe(NetworkDeferralReason::StatusRefreshBudget)
        ->and($result->retryAt->value->getTimestamp())->toBeLessThan(time() + 40);
    // Version/rate changes cannot reset debt or grandfather the reserved authorization.
    DB::table('prc_network_budgets')->where('scope_key', $f->url->origin->budgetScopeKey())->update(['emission_interval_us' => 30_000_000, 'policy_version' => 2]);
    expect($f->repo->claimSend($p, $c))->toBeInstanceOf(TransportBlocked::class)
        ->and($this->wire->receipts())->toHaveCount(0)->and(DB::table('prc_request_attempts')->count())->toBe(17);
});
