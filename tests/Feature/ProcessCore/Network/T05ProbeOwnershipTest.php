<?php

declare(strict_types=1);

use App\Dto\Procurement\Network\HttpRouteBinding;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\ProbeClaim;
use App\Dto\Procurement\Network\TransferBudget;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Network\TransportSucceeded;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Network\IdempotencyClass;
use App\Enums\Procurement\Network\OutboundOperationKind;
use App\Enums\Procurement\Network\TrafficClass;
use App\Enums\Procurement\Network\TransportFailure;
use App\Services\Procurement\Network\OutboundExecutor;
use App\Services\Procurement\Network\ProbeScheduler;
use Illuminate\Support\Facades\DB;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\Proxy\HealthFixtures as F;

beforeEach(function (): void {
    PermitFixtures::clean();
    $this->wire = new WireFixture;
    $this->f = new F($this->wire);
    foreach (['prc_route_health', 'prc_route_target_health', 'prc_target_health'] as $table) {
        DB::table($table)->update(['state' => 'unknown']);
    }
});
afterEach(function (): void {
    $this->wire->stop();
    PermitFixtures::clean();
});
pest()->group('t05', 't06', 'p24');

test('20 independent PostgreSQL contenders новогоtarget дают один compound token одинactualprobe zero ordinary sends', function (): void {
    $f = $this->f;
    $direct = new HttpRouteBinding($f->permits->route->routeId);
    $ordinary = app(OutboundExecutor::class)->execute($f->permits->plan(), $f->permits->context(), $f->gate([$direct]),
        $this->wire->policy(), new TransferBudget(1000000, 1000000));
    expect($ordinary)->toBeInstanceOf(TransportDeferred::class)->and(DB::table('prc_request_attempts')->count())->toBe(0);
    $workers = [];
    $contexts = [];
    for ($i = 0; $i < 20; $i++) {
        $context = $f->permits->context(kind: OutboundOperationKind::Probe, probeToken: LedgerFixtures::uuid());
        $contexts['w'.$i] = $context;
        $workers['w'.$i] = '$process = "runtime-p24-".bin2hex(random_bytes(6)); putenv("PROCESS_CORE_PROCESS=".$process);'
            .'\Tests\Support\TestFilesystemIsolation::configure(app()); try {'
            .'$context = \Tests\Support\ProcessCore\Http\PermitFixtures::importContext('.var_export($context->lease()->id->value, true).');'
            .'$plan = new \App\Dto\Procurement\Network\OutboundRequestPlan(\Tests\Support\ProcessCore\LedgerFixtures::uuid(), new \App\Dto\Procurement\Process\ProcessUuid('.var_export($f->permits->target->value, true).'), "GET", new \App\Dto\Procurement\Network\SafeUrl('.var_export($f->permits->url->reveal(), true).'), \Tests\Support\ProcessCore\Http\TransportFixtures::profile(), \App\Enums\Procurement\Network\TrafficClass::Detail, \App\Enums\Procurement\Network\IdempotencyClass::ReadOnly);'
            .'$selection = new \App\Dto\Procurement\Network\NetworkRouteSelection(new \App\Dto\Procurement\Process\ProcessUuid('.var_export($direct->id->value, true).'));'
            .'$claim = app(\App\Services\Procurement\Network\ProbeScheduler::class)->claim($plan, $context, $selection);'
            .'return ["won" => $claim instanceof \App\Dto\Procurement\Network\ProbeClaim, "epochs" => $claim instanceof \App\Dto\Procurement\Network\ProbeClaim ? $claim->epochs : [], "token" => $claim instanceof \App\Dto\Procurement\Network\ProbeClaim ? $claim->token->value : null, "until" => $claim instanceof \App\Dto\Procurement\Network\ProbeClaim ? $claim->until->format("c") : null, "pid" => \Illuminate\Support\Facades\DB::selectOne("SELECT pg_backend_pid() AS pid")->pid];'
            .'} finally { \Tests\Support\TestFilesystemIsolation::cleanup($process); }';
    }
    $results = ConcurrentTransactions::concurrently($workers);
    expect(array_unique(array_column($results, 'pid')))->toHaveCount(20);
    $winners = array_filter($results, fn ($r) => $r['won']);
    expect($winners)->toHaveCount(1)->and(DB::table('prc_request_attempts')->count())->toBe(0);
    $name = array_key_first($winners);
    $winner = $winners[$name];
    $claim = new ProbeClaim(new ProcessUuid($winner['token']), $winner['epochs'], new DateTimeImmutable($winner['until']));
    expect($claim->epochs)->toHaveCount(3);
    $result = app(OutboundExecutor::class)->execute($f->permits->plan(), $contexts[$name], $f->gate([$direct], $claim),
        $this->wire->policy(), new TransferBudget(1000000, 1000000));
    expect($result)->toBeInstanceOf(TransportSucceeded::class)->and($this->wire->receipts())->toHaveCount(1)
        ->and(DB::table('prc_request_attempts')->count())->toBe(1);
    foreach (['prc_route_health', 'prc_route_target_health', 'prc_target_health'] as $table) {
        expect(DB::table($table)->sole()->state)->toBe('closed')->and(DB::table($table)->sole()->probe_token)->toBeNull();
    }
});

test('failed second scope не меняет первый unknown scope и не сохраняет частичный token', function (): void {
    $f = $this->f;
    DB::table('prc_route_target_health')->update(['state' => 'open', 'open_until' => DB::raw("clock_timestamp() + interval '300 seconds'")]);
    $before = (array) DB::table('prc_route_health')->sole();
    $claim = app(ProbeScheduler::class)->claim($f->permits->plan(), $f->permits->context(kind: OutboundOperationKind::Probe, probeToken: LedgerFixtures::uuid()), $f->permits->route);
    expect($claim)->toBeInstanceOf(TransportDeferred::class)->and((array) DB::table('prc_route_health')->sole())->toBe($before)
        ->and(DB::table('prc_target_health')->sole()->probe_token)->toBeNull();
});

test('expired token новый contender получает новое поколение stale owner не закрывает его', function (): void {
    $f = $this->f;
    $first = $f->permits->context(kind: OutboundOperationKind::Probe, probeToken: LedgerFixtures::uuid());
    $oldClaim = app(ProbeScheduler::class)->claim($f->permits->plan(), $first, $f->permits->route);
    expect($oldClaim)->toBeInstanceOf(ProbeClaim::class);
    $oldSend = $f->authorize($first);
    foreach (['prc_route_health', 'prc_route_target_health', 'prc_target_health'] as $table) {
        DB::table($table)->update(['probe_until' => DB::raw("clock_timestamp() - interval '1 second'")]);
    }
    expect(app(ProbeScheduler::class)->claim($f->permits->plan(), $first, $f->permits->route))->toBeInstanceOf(TransportBlocked::class);
    $second = $f->permits->context(kind: OutboundOperationKind::Probe, probeToken: LedgerFixtures::uuid());
    $claim = app(ProbeScheduler::class)->claim($f->permits->plan(), $second, $f->permits->route);
    expect($claim)->toBeInstanceOf(ProbeClaim::class)->and($claim->token->value)->not->toBe($oldClaim->token->value);
    $f->permits->repo->record($oldSend->ticket->plan->physicalRequestId, F::success());
    foreach (['prc_route_health', 'prc_route_target_health', 'prc_target_health'] as $table) {
        expect(DB::table($table)->sole()->state)->toBe('half_open')->and(DB::table($table)->sole()->probe_token)->toBe($claim->token->value);
    }
    expect(DB::table('prc_observations')->sole()->stale)->toBeTrue();
    $direct = new HttpRouteBinding($f->permits->route->routeId);
    $result = app(OutboundExecutor::class)->execute($f->permits->plan(), $second, $f->gate([$direct], $claim),
        $this->wire->policy(), new TransferBudget(1000000, 1000000));
    expect($result)->toBeInstanceOf(TransportSucceeded::class)->and($this->wire->receipts())->toHaveCount(1);
});

test('probe scope token нельзя использовать для другогоtarget а cancellation освобождает весьset безfailures', function (): void {
    $f = $this->f;
    $context = $f->permits->context(kind: OutboundOperationKind::Probe, probeToken: LedgerFixtures::uuid());
    $claim = app(ProbeScheduler::class)->claim($f->permits->plan(), $context, $f->permits->route);
    $target = (array) DB::table('prc_source_targets')->sole();
    $target['id'] = LedgerFixtures::uuid()->value;
    $target['target_key'] = 'other';
    DB::table('prc_source_targets')->insert($target);
    DB::table('prc_target_health')->insert(['id' => LedgerFixtures::uuid()->value, 'target_id' => $target['id'], 'state' => 'closed',
        'availability' => 'available', 'health_epoch' => 1, 'state_version' => 1, 'recent_openings' => 0]);
    DB::table('prc_route_target_health')->insert(['id' => LedgerFixtures::uuid()->value, 'route_id' => $f->permits->route->routeId->value,
        'target_id' => $target['id'], 'state' => 'closed', 'health_epoch' => 1, 'state_version' => 1, 'recent_openings' => 0, 'consecutive_failures' => 0]);
    $other = new OutboundRequestPlan(LedgerFixtures::uuid(), new ProcessUuid($target['id']), 'GET',
        $f->permits->url, $f->permits->profile, TrafficClass::Detail, IdempotencyClass::ReadOnly);
    expect($f->permits->repo->register($other, $context, $f->permits->route))->toBeInstanceOf(TransportBlocked::class);
    // Remove only the additional, unreferenced fixture authorities before checking the owned set.
    DB::table('prc_route_target_health')->where('target_id', $target['id'])->delete();
    DB::table('prc_target_health')->where('target_id', $target['id'])->delete();
    DB::table('prc_source_targets')->where('id', $target['id'])->delete();
    $permit = $f->authorize($context);
    $f->permits->repo->record($permit->ticket->plan->physicalRequestId, F::failure(TransportFailure::Cancelled));
    foreach (['prc_route_health', 'prc_route_target_health', 'prc_target_health'] as $table) {
        expect(DB::table($table)->sole()->state)->toBe('unknown')->and(DB::table($table)->sole()->probe_token)->toBeNull()
            ->and(DB::table($table)->sole()->recent_openings)->toBe(0);
    }
    expect(app(ProbeScheduler::class)->claim($f->permits->plan(), $context, $f->permits->route))->toBeInstanceOf(TransportBlocked::class);
});

test('concurrent duplicate outcome aplicaция меняетhealth одинраз атомарно с receipt release', function (): void {
    foreach (['prc_route_health', 'prc_route_target_health', 'prc_target_health'] as $table) {
        DB::table($table)->update(['state' => 'closed']);
    }
    $permit = $this->f->authorize($this->f->permits->context());
    $id = $permit->ticket->plan->physicalRequestId->value;
    $worker = '$token = "runtime-p24-dedup-".bin2hex(random_bytes(6)); putenv("PROCESS_CORE_PROCESS=".$token);'
        .'\Tests\Support\TestFilesystemIsolation::configure(app()); try {'
        .'app(\App\Repositories\Procurement\Network\NetworkPermitRepository::class)->record(new \App\Dto\Procurement\Process\ProcessUuid('.var_export($id, true).'), \Tests\Support\ProcessCore\Proxy\HealthFixtures::failure());'
        .'return ["pid" => \Illuminate\Support\Facades\DB::selectOne("SELECT pg_backend_pid() AS pid")->pid];'
        .'} finally { \Tests\Support\TestFilesystemIsolation::cleanup($token); }';
    $results = ConcurrentTransactions::concurrently(['one' => $worker, 'two' => $worker]);
    expect(array_unique(array_column($results, 'pid')))->toHaveCount(2)
        ->and(DB::table('prc_observations')->count())->toBe(1)->and(DB::table('prc_observation_applications')->count())->toBe(3)
        ->and(DB::table('prc_route_target_health')->sole()->consecutive_failures)->toBe(1)
        ->and(DB::table('prc_network_permits')->sole()->state)->toBe('released');
});
