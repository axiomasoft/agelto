<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\Postgres\ConcurrentTransactions;
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
pest()->group('quota-fair-queue', 'bounded-network-demand', 'provider-send-fencing');

function permitWorker(string $operation, string $ticket, string $url, bool $alreadyReserved = false): string
{
    return '$process = "runtime-p23-child-".bin2hex(random_bytes(6)); putenv("PROCESS_CORE_PROCESS=".$process);'
        .'\Tests\Support\TestFilesystemIsolation::configure(app()); try {'
        .'$context = \Tests\Support\ProcessCore\Http\PermitFixtures::importContext('.var_export($operation, true).');'
        .'$ticket = \Tests\Support\ProcessCore\Http\PermitFixtures::importTicket('.var_export($ticket, true).', '.var_export($url, true).');'
        .'$repo = app(\App\Repositories\Procurement\Network\NetworkPermitRepository::class);'
        .($alreadyReserved
            ? '$permit = \\Tests\\Support\\ProcessCore\\Http\\PermitFixtures::importPermit($ticket);'
            : 'do { $context->tick(); $permit = $repo->reserve($ticket, $context); if ($permit instanceof \App\Dto\Procurement\Network\NetworkPermitWait) usleep(10000); } while ($permit instanceof \App\Dto\Procurement\Network\NetworkPermitWait);')
        .'if (! $permit instanceof \App\Dto\Procurement\Network\ScheduledNetworkPermit) throw new \LogicException("Not granted: ".get_debug_type($permit));'
        .'do { $context->tick(); $claim = $repo->claimSend($permit, $context); if ($claim instanceof \App\Dto\Procurement\Network\NetworkPermitWait) usleep(10000); } while ($claim instanceof \App\Dto\Procurement\Network\NetworkPermitWait);'
        .'$sent = $claim instanceof \App\Dto\Procurement\Network\NetworkSendClaim && $claim->claimed;'
        .'if ($sent) { $policy = new \App\Services\Procurement\Network\UrlPolicy([$ticket->plan->url->origin], ["fixture.test" => ["127.0.0.1"]]);'
        .'$request = new \App\Dto\Procurement\Network\AuthorizedHttpRequest($ticket->plan, new \App\Dto\Procurement\Network\HttpRouteBinding($ticket->route->routeId));'
        .'$result = app(\App\Services\Procurement\Network\HttpTransport::class)->sendOnce($request, $policy->resolve($ticket->plan->url, $context, 1), new \App\Dto\Procurement\Network\HttpRequestPayload, $context, new \App\Dto\Procurement\Network\TransferBudget(1000000, 1000000)); $repo->record($ticket->plan->physicalRequestId, $result); }'
        .'return ["sent" => $sent, "pid" => \Illuminate\Support\Facades\DB::selectOne("SELECT pg_backend_pid() AS pid")->pid];'
        .'} finally { \Tests\Support\TestFilesystemIsolation::cleanup($process); }';
}

test('8 independent PG contenders получают FIFO grants GCRA authorizations и 8 actual receipts', function (): void {
    $f = $this->f;
    $workers = [];
    for ($i = 0; $i < 8; $i++) {
        $c = $f->context();
        $ticket = $f->ticket($c);
        $workers['w'.$i] = permitWorker($c->lease()->id->value, $ticket->id->value, $f->url->reveal());
    }
    $results = ConcurrentTransactions::concurrently($workers);
    expect(array_unique(array_column($results, 'pid')))->toHaveCount(8)
        ->and(array_filter(array_column($results, 'sent')))->toHaveCount(8)
        ->and($this->wire->receipts())->toHaveCount(8)
        ->and(DB::table('prc_request_attempts')->count())->toBe(8);
    $rows = DB::select('SELECT extract(epoch FROM p.authorized_at) * 1000000 AS authorized, extract(epoch FROM p.slot_at) * 1000000 AS slot, d.ticket_seq
        FROM prc_network_permits p JOIN prc_network_demands d ON d.id = p.demand_id ORDER BY d.ticket_seq');
    $slots = array_map(fn ($r) => (float) $r->slot, $rows);
    foreach (range(1, 7) as $i) {
        expect($slots[$i] - $slots[$i - 1])->toBeGreaterThanOrEqual(100000.0);
    }
    $authorized = array_map(fn ($r) => (float) $r->authorized, $rows);
    sort($authorized);
    foreach (range(1, 7) as $i) {
        expect($authorized[$i] - $authorized[$i - 1])->toBeGreaterThanOrEqual(100000.0);
    }
});

test('concurrent duplicate claimSend даёт один actual HTTP и один durable intent', function (): void {
    $f = $this->f;
    $c = $f->context();
    $permit = $f->reserve($c);
    $code = permitWorker($c->lease()->id->value, $permit->ticket->id->value, $f->url->reveal(), true);
    $results = ConcurrentTransactions::concurrently(['a' => $code, 'b' => $code]);
    expect(array_unique(array_column($results, 'pid')))->toHaveCount(2)
        ->and(array_filter(array_column($results, 'sent')))->toHaveCount(1)
        ->and($this->wire->receipts())->toHaveCount(1)->and(DB::table('prc_request_attempts')->count())->toBe(1);
});
