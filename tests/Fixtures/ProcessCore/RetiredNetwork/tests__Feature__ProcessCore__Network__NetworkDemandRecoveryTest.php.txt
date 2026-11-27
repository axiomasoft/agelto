<?php

declare(strict_types=1);

use App\Dto\Procurement\Network\NetworkDemandTicket;
use App\Dto\Procurement\Network\NetworkPermitWait;
use App\Dto\Procurement\Network\ScheduledNetworkPermit;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Enums\Procurement\Network\OutboundOperationKind;
use App\Services\Procurement\Network\LegacyBridge;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\Http\PermitFixtures as F;
use Tests\Support\ProcessCore\LedgerFixtures;

beforeEach(function (): void {
    F::clean();
    $this->f = new F;
});
afterEach(fn () => F::clean());
pest()->group('network-demand-recovery');

test('stale head expiry или revoked lease не блокирует FIFO и старый ticket не оживает', function (bool $leaseLost): void {
    $f = $this->f;
    $a = $f->context();
    $ta = $f->ticket($a);
    $b = $f->context();
    $tb = $f->ticket($b);
    if ($leaseLost) {
        DB::table('prc_outbound_operations')->where('id', $a->lease()->id->value)->update(['lease_until' => DB::raw("clock_timestamp() - interval '1 second'")]);
    } else {
        DB::table('prc_network_demands')->where('id', $ta->id->value)->update(['expires_at' => DB::raw("clock_timestamp() - interval '1 second'")]);
    }
    expect($f->repo->reserve($tb, $b))->toBeInstanceOf(ScheduledNetworkPermit::class)
        ->and(DB::table('prc_network_demands')->where('id', $ta->id->value)->sole()->state)->toBe('expired');
    $again = $f->repo->register($ta->plan, $a, $f->route);
    expect($again instanceof TransportBlocked || $again instanceof TransportDeferred)->toBeTrue();
})->with([false, true]);

test('crash reserved expiry освобождает inflight но не rate debt и не возрождает permit', function (): void {
    $f = $this->f;
    $a = $f->context();
    $p = $f->reserve($a);
    $debt = DB::table('prc_network_budgets')->orderBy('id')->pluck('tat')->all();
    DB::table('prc_network_permits')->where('id', $p->id->value)->update(['slot_at' => DB::raw("clock_timestamp() - interval '35 seconds'"), 'expires_at' => DB::raw("clock_timestamp() - interval '1 second'")]);
    expect($f->repo->reap())->toBe(1)->and($f->repo->reap())->toBe(0)
        ->and(DB::table('prc_network_budgets')->orderBy('id')->pluck('tat')->all())->toBe($debt)
        ->and($f->repo->claimSend($p, $a))->toBeInstanceOf(TransportBlocked::class);
    expect($f->repo->register($p->ticket->plan, $a, $f->route))->toBeInstanceOf(NetworkDemandTicket::class);
    expect($f->repo->reserve($p->ticket, $a))->toBeInstanceOf(TransportDeferred::class);
});

test('probe использует обычный FIFO origin и отдельную inflight scope без priority grant', function (): void {
    $f = $this->f;
    $ordinary = $f->context();
    $head = $f->ticket($ordinary);
    $token = LedgerFixtures::uuid();
    DB::table('prc_target_health')->update(['state' => 'half_open', 'probe_token' => $token->value, 'probe_until' => DB::raw("clock_timestamp() + interval '90 seconds'")]);
    $probe = $f->context(kind: OutboundOperationKind::Probe, probeToken: $token);
    $ticket = $f->ticket($probe);
    expect($f->repo->reserve($ticket, $probe))->toBeInstanceOf(NetworkPermitWait::class)
        ->and(DB::table('prc_network_permits')->count())->toBe(0);
    expect($f->repo->reserve($head, $ordinary))->toBeInstanceOf(TransportBlocked::class);
    $permit = $f->repo->reserve($ticket, $probe);
    expect($permit)->toBeInstanceOf(ScheduledNetworkPermit::class)
        ->and(DB::table('prc_network_permit_scopes')->where('permit_id', $permit->id->value)->count())->toBe(3);
});

test('bridge finally cleanup отменяет reserved без physical request и без сброса debt', function (): void {
    $f = $this->f;
    $permit = null;
    app(LegacyBridge::class)->run('fixture.call', null, ['acquisition:platform:'.$f->code], 120, LedgerFixtures::hash('policy'), function ($context) use ($f, &$permit): void {
        $permit = $f->reserve($context);
    });
    expect($permit)->toBeInstanceOf(ScheduledNetworkPermit::class)
        ->and(DB::table('prc_network_permits')->where('id', $permit->id->value)->sole()->state)->toBe('released')
        ->and(DB::table('prc_request_attempts')->count())->toBe(0);
});

test('bounded reaper не застревает на первых idle origins и обрабатывает due authority', function (): void {
    $f = $this->f;
    $c = $f->context();
    $p = $f->reserve($c);
    for ($i = 0; $i < 64; $i++) {
        DB::table('prc_network_budgets')->insert(['id' => LedgerFixtures::uuid()->value,
            'scope_key' => 'origin:'.str_pad(dechex($i), 64, '0', STR_PAD_LEFT), 'emission_interval_us' => 4_000_000,
            'burst' => 1, 'max_inflight' => 1, 'policy_version' => 1]);
    }
    DB::table('prc_network_permits')->where('id', $p->id->value)->update([
        'slot_at' => DB::raw("clock_timestamp() - interval '35 seconds'"), 'expires_at' => DB::raw("clock_timestamp() - interval '1 second'")]);
    expect($f->repo->reap(1))->toBe(1)
        ->and(DB::table('prc_network_permits')->where('id', $p->id->value)->sole()->state)->toBe('expired');
});
