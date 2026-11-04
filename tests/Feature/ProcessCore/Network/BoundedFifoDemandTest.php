<?php

declare(strict_types=1);

use App\Dto\Procurement\Network\NetworkDemandTicket;
use App\Dto\Procurement\Network\NetworkPermitWait;
use App\Dto\Procurement\Network\ScheduledNetworkPermit;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Enums\Procurement\Network\NetworkDeferralReason;
use App\Services\Procurement\Network\OutboundPolicy;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\Http\PermitFixtures as F;

beforeEach(function (): void {
    F::clean();
    $this->f = new F;
});
afterEach(fn () => F::clean());
pest()->group('bounded-network-demand', 'quota-fair-queue');

test('stable physical key сохраняет ticket и FIFO при 64 live demands без probe bypass', function (): void {
    $f = $this->f;
    // Accelerated DB rate for 64-demand ordering proof, no fake scheduler/locks.
    DB::table('prc_network_budgets')->update(['emission_interval_us' => 1, 'max_inflight' => 32]);
    $tickets = $contexts = [];
    for ($i = 0; $i < 64; $i++) {
        $contexts[] = $c = $f->context();
        $tickets[] = $f->ticket($c);
    }
    $repeat = $f->repo->register($tickets[0]->plan, $contexts[0], $f->route);
    expect($repeat)->toBeInstanceOf(NetworkDemandTicket::class)
        ->and($repeat->id->value)->toBe($tickets[0]->id->value)->and($repeat->ticketSeq)->toBe($tickets[0]->ticketSeq);
    $overflow = $f->repo->register($f->plan(), $f->context(), $f->route);
    expect($overflow)->toBeInstanceOf(TransportDeferred::class)->and($overflow->reason)->toBe(NetworkDeferralReason::CapacityWait)
        ->and(DB::table('prc_network_demands')->count())->toBe(64);
    $debt = DB::table('prc_network_budgets')->orderBy('id')->pluck('tat')->all();
    expect($f->repo->reserve($tickets[63], $contexts[63]))->toBeInstanceOf(NetworkPermitWait::class)
        ->and(DB::table('prc_network_budgets')->orderBy('id')->pluck('tat')->all())->toBe($debt);
    foreach ($tickets as $i => $ticket) {
        $permit = $f->repo->reserve($ticket, $contexts[$i]);
        expect($permit)->toBeInstanceOf(ScheduledNetworkPermit::class)
            ->and(DB::table('prc_network_demands')->where('state', 'granted')->count())->toBe($i + 1);
        $f->repo->abandon($ticket, $contexts[$i]);
    }
    expect(DB::table('prc_network_permits')->count())->toBe(64)
        ->and(DB::table('prc_network_demands')->where('state', 'queued')->count())->toBe(0);
});

test('denied second scope и полный timeout fit не меняют ни один TAT и не дают partial permit', function (): void {
    $f = $this->f;
    $first = $f->reserve($f->context());
    $secondContext = $f->context();
    $second = $f->ticket($secondContext);
    $before = DB::table('prc_network_budgets')->orderBy('id')->pluck('tat')->all();
    expect($f->repo->reserve($second, $secondContext))->toBeInstanceOf(NetworkPermitWait::class)
        ->and(DB::table('prc_network_permits')->count())->toBe(1)
        ->and(DB::table('prc_network_budgets')->orderBy('id')->pluck('tat')->all())->toBe($before);
    $f->repo->abandon($first->ticket, F::importContext(DB::table('prc_network_demands')->where('id', $first->ticket->id->value)->sole()->operation_id));
    DB::table('prc_network_budgets')->update(['tat' => DB::raw("clock_timestamp() + interval '21 seconds'")]);
    $before = DB::table('prc_network_budgets')->orderBy('id')->pluck('tat')->all();
    $refused = $f->repo->reserve($second, $secondContext);
    expect($refused)->toBeInstanceOf(TransportDeferred::class)->and($refused->reason)->toBe(NetworkDeferralReason::RateLimit)
        ->and(DB::table('prc_network_budgets')->orderBy('id')->pluck('tat')->all())->toBe($before)
        ->and(DB::table('prc_network_permit_scopes')->where('permit_id', $first->id->value)->count())->toBe(2);
});

test('capacity polling ограничен original window и не продлевается renewal', function (): void {
    $f = $this->f;
    $f->reserve($f->context());
    $context = $f->context();
    $ticket = $f->ticket($context);
    DB::table('prc_network_demands')->where('id', $ticket->id->value)->update(['registered_at' => DB::raw("clock_timestamp() - interval '21 seconds'")]);
    $result = $f->repo->reserve($ticket, $context);
    expect($result)->toBeInstanceOf(TransportDeferred::class)->and($result->reason)->toBe(NetworkDeferralReason::CapacityWait)
        ->and($result->retryAt->value->getTimestamp())->toBeGreaterThan(time() + 20)
        ->and(DB::table('prc_network_demands')->where('id', $ticket->id->value)->sole()->state)->toBe('cancelled');
});

test('scheduling policy отвергает превышение FIFO wait cleanup и quota bounds', function (): void {
    foreach (['max_inline_wait_seconds' => 21, 'max_pending_demands_per_origin' => 65,
        'ticket_ttl_seconds' => 6, 'ticket_renew_seconds' => 2, 'permit_cleanup_reserve_seconds' => 29, 'status_capacity_percent' => 31] as $key => $value) {
        $config = new Repository(['outbound' => require base_path('config/outbound.php')]);
        $config->set('outbound.scheduling.'.$key, $value);
        expect(fn () => new OutboundPolicy($config))->toThrow(InvalidArgumentException::class, 'outbound.scheduling');
    }
});
