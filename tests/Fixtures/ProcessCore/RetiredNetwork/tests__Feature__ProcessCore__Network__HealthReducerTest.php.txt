<?php

declare(strict_types=1);

use App\Dto\Procurement\Network\HealthAuthorization;
use App\Dto\Procurement\Network\HealthSnapshot;
use App\Enums\Procurement\Network\CircuitState;
use App\Enums\Procurement\Network\HealthScope;
use App\Enums\Procurement\Network\HealthSignal;
use App\Enums\Procurement\Network\TransportFailure as Failure;
use App\Repositories\Procurement\Network\HealthRepository;
use App\Services\Procurement\Network\HealthPolicy;
use App\Services\Procurement\Network\HealthReducer;
use App\Services\Procurement\Network\OutboundPolicy;
use Illuminate\Config\Repository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
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
pest()->group('route-health', 'network-route-policy', 'work-controls-and-fences');

test('четыре consecutive failures не открывают direct route-target пятый открывает только пару', function (): void {
    for ($i = 0; $i < 4; $i++) {
        $this->f->outcome(F::failure());
    }
    expect(DB::table('prc_route_target_health')->sole()->state)->toBe('closed');
    $this->f->outcome(F::failure());
    $row = DB::table('prc_route_target_health')->sole();
    expect($row->state)->toBe('open')->and($row->recent_openings)->toBe(1)
        ->and(DB::table('prc_route_health')->sole()->state)->toBe('closed')
        ->and(DB::table('prc_target_health')->sole()->state)->toBe('closed')
        ->and(DB::table('prc_target_health')->sole()->availability)->toBe('suspect');
    $seconds = DB::selectOne('SELECT extract(epoch FROM (open_until - last_failure_at)) AS n FROM prc_route_target_health')->n;
    expect((float) $seconds)->toBe(300.0);
});

test('rolling sample 9 недостаточен sample10 5fail не open и 6fail opens без consecutive branch', function (array $sequence, bool $opens): void {
    foreach ($sequence as $index => $failed) {
        $this->f->outcome($failed ? F::failure() : F::success());
        if ($index < 9) {
            expect(DB::table('prc_route_target_health')->sole()->state)->toBe('closed');
        }
    }
    expect(DB::table('prc_route_target_health')->sole()->state)->toBe($opens ? 'open' : 'closed');
})->with([
    '5/10' => [[false, true, false, true, false, true, false, true, false, true], false],
    '6/10' => [[true, false, true, false, true, false, true, false, true, true], true],
]);

test('старый epoch success хранится stale и duplicate outcome не меняет current counters', function (): void {
    $f = $this->f;
    $old = $f->authorize($f->permits->context());
    // Permit remains in flight; allow this independent test stream under the same origin limits.
    DB::table('prc_network_budgets')->update(['max_inflight' => 2]);
    for ($i = 0; $i < 5; $i++) {
        $f->outcome(F::failure());
    }
    $before = DB::table('prc_route_target_health')->sole();
    $f->permits->repo->record($old->ticket->plan->physicalRequestId, F::success());
    $f->permits->repo->record($old->ticket->plan->physicalRequestId, F::success());
    expect((array) DB::table('prc_route_target_health')->sole())->toBe((array) $before)
        ->and(DB::table('prc_observations')->where('observation_id', $old->ticket->plan->physicalRequestId->value)->sole()->stale)->toBeTrue()
        ->and(DB::table('prc_observations')->count())->toBe(6)
        ->and(DB::table('prc_observation_applications')->count())->toBe(18);
});

test('cancellation TLS byte-limit decode storage config не proxy failures и не denominator', function (Failure $failure): void {
    for ($i = 0; $i < 6; $i++) {
        $this->f->outcome(F::failure($failure));
    }
    expect(DB::table('prc_route_target_health')->sole()->consecutive_failures)->toBe(0)
        ->and(DB::table('prc_route_health')->sole()->consecutive_failures)->toBe(0)
        ->and(DB::table('prc_target_health')->sole()->state)->toBe('closed');
})->with([Failure::Cancelled, Failure::Tls, Failure::ByteLimit, Failure::Decode, Failure::Storage, Failure::Configuration]);

test('только proven proxy-phase failures открывают global с cooldown1800', function (): void {
    $proxy = $this->f->proxy();
    for ($i = 0; $i < 5; $i++) {
        $this->f->outcome(F::failure(Failure::ProxyConnect), $proxy);
        if ($i < 4) {
            expect(DB::table('prc_route_health')->where('route_id', $proxy->id->value)->sole()->state)->toBe('closed');
        }
    }
    $row = DB::table('prc_route_health')->where('route_id', $proxy->id->value)->sole();
    expect($row->state)->toBe('open')->and(DB::table('prc_target_health')->sole()->availability)->toBe('unknown');
    expect((float) DB::selectOne('SELECT extract(epoch FROM (open_until - last_failure_at)) AS n FROM prc_route_health WHERE route_id = ?', [$proxy->id->value])->n)->toBe(1800.0);
});

test('два distinct explicit independence groups открывают target неизвестные и одинаковые не открывают', function (?string $a, ?string $b, bool $opens): void {
    $first = $this->f->proxy(group: $a);
    $second = $this->f->proxy(group: $b);
    $this->f->outcome(F::failure(), $first);
    expect(DB::table('prc_target_health')->sole()->state)->toBe('closed');
    $this->f->outcome(F::failure(), $second);
    expect(DB::table('prc_target_health')->sole()->state)->toBe($opens ? 'open' : 'closed');
})->with([[null, null, false], ['same', 'same', false], ['one', 'two', true]]);

test('ordinary успех не уменьшает openings до600 секунд stability и decay не повторяется быстрыми success', function (): void {
    DB::table('prc_route_target_health')->update(['recent_openings' => 3, 'stable_since' => DB::raw("clock_timestamp() - interval '599 seconds'")]);
    $this->f->outcome(F::success());
    expect(DB::table('prc_route_target_health')->sole()->recent_openings)->toBe(3);
    DB::table('prc_route_target_health')->update(['stable_since' => DB::raw("clock_timestamp() - interval '601 seconds'")]);
    $this->f->outcome(F::success());
    $this->f->outcome(F::success());
    expect(DB::table('prc_route_target_health')->sole()->recent_openings)->toBe(2);
});

test('health config boot validation rejects invalid keys thresholds and caps', function (): void {
    foreach (['route_target_failure_percent' => 101, 'global_cap_seconds' => 1, 'probe_reserve_seconds' => 1, 'stable_decay_seconds' => 0] as $key => $value) {
        $config = new Repository(['outbound' => config('outbound')]);
        $config->set('outbound.health.'.$key, $value);
        expect(fn () => new HealthPolicy($config))->toThrow(InvalidArgumentException::class);
        expect(fn () => new OutboundPolicy($config))->toThrow(InvalidArgumentException::class);
    }
});

test('rolling sample исключает ignored outcomes а неeligible ошибки не разбавляют denominator', function (): void {
    for ($i = 0; $i < 4; $i++) {
        $this->f->outcome(F::failure());
        $this->f->outcome(F::success());
    }
    for ($i = 0; $i < 4; $i++) {
        $this->f->outcome(F::failure(Failure::Cancelled));
    }
    $this->f->outcome(F::failure());
    expect(DB::table('prc_route_target_health')->sole()->state)->toBe('closed');
    $this->f->outcome(F::failure());
    expect(DB::table('prc_route_target_health')->sole()->state)->toBe('open');
});

test('cooldown doubles with cap и owned probe success не сбрасывает recent openings', function (): void {
    $reducer = app(HealthReducer::class);
    $now = new DateTimeImmutable('2026-10-29T01:00:00Z');
    foreach ([[HealthScope::Route, 1800, 21600], [HealthScope::RouteTarget, 300, 3600]] as [$scope, $base, $cap]) {
        foreach ([0, 1, 20] as $openings) {
            $row = new HealthSnapshot($scope, 'fixture', CircuitState::HalfOpen,
                3, 0, $openings, null, null, null, null, null);
            $failed = $reducer->reduce($row, HealthSignal::Failure, 'timeout', $now, 1, 1, 0, false);
            expect($failed->openUntil->getTimestamp() - $now->getTimestamp())->toBe(min($cap, $base * 2 ** $openings));
            $success = $reducer->reduce($row, HealthSignal::Success, 'http_200', $now, 1, 0, 0, false);
            expect($success->openings)->toBe($openings);
        }
    }
});

test('explicit maintenance evidence открываетtarget минимум900s и respects longer retryAfter', function (): void {
    $permit = $this->f->authorize($this->f->permits->context());
    $until = HealthRepository::now()->modify('+1200 seconds');
    app(HealthRepository::class)->record($permit->ticket->plan->physicalRequestId, F::success(), $until);
    $row = DB::table('prc_target_health')->sole();
    expect($row->state)->toBe('open')->and($row->availability)->toBe('unavailable')->and($row->reason_code)->toBe('maintenance')
        ->and(new DateTimeImmutable($row->open_until))->toBeGreaterThanOrEqual($until->modify('-1 second'))
        ->and(DB::table('prc_route_health')->sole()->state)->toBe('closed');
});

test('rolling600s не считает expired failure evidence', function (): void {
    for ($i = 0; $i < 4; $i++) {
        $this->f->outcome(F::failure());
        $this->f->outcome(F::success());
    }
    DB::table('prc_observations')->where('reason_code', 'timeout')->update(['occurred_at' => DB::raw("clock_timestamp() - interval '601 seconds'")]);
    for ($i = 0; $i < 3; $i++) {
        $this->f->outcome(F::failure());
        $this->f->outcome(F::success());
    }
    expect(DB::table('prc_route_target_health')->sole()->state)->toBe('closed');
});

test('observation insert fault откатывает request facts health dedup и permit release вместе', function (): void {
    $permit = $this->f->authorize($this->f->permits->context());
    DB::unprepared("CREATE FUNCTION p24_reject_observation() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION USING ERRCODE = ''23514'', MESSAGE = ''fixture observation fault''; END';
        CREATE TRIGGER p24_reject_observation BEFORE INSERT ON prc_observations FOR EACH ROW EXECUTE FUNCTION p24_reject_observation()");
    try {
        expect(fn () => $this->f->permits->repo->record($permit->ticket->plan->physicalRequestId, F::failure()))->toThrow(QueryException::class);
        expect(DB::table('prc_request_attempts')->sole()->finished_at)->toBeNull()
            ->and(DB::table('prc_network_permits')->sole()->state)->toBe('sent')
            ->and(DB::table('prc_route_target_health')->sole()->consecutive_failures)->toBe(0)
            ->and(DB::table('prc_observations')->count())->toBe(0)->and(DB::table('prc_observation_applications')->count())->toBe(0);
    } finally {
        DB::unprepared('DROP TRIGGER p24_reject_observation ON prc_observations; DROP FUNCTION p24_reject_observation()');
    }
    $this->f->permits->repo->record($permit->ticket->plan->physicalRequestId, F::failure());
    expect(DB::table('prc_route_target_health')->sole()->consecutive_failures)->toBe(1);
});

test('authorization metadata rejects unknown keys schema and invalid semantics', function (): void {
    $metadata = ['kind' => 'proxy', 'credential_version' => 2, 'independence_group' => 'group-one', 'probe_token' => null];
    $class = HealthAuthorization::class;
    expect($class::decode(json_encode($metadata), 1)->toArray())->toBe($metadata);
    $expected = [InvalidArgumentException::class, LogicException::class, InvalidArgumentException::class, InvalidArgumentException::class, ValueError::class, LogicException::class, LogicException::class];
    foreach ([array_replace($metadata, ['credential_version' => 0]), array_replace($metadata, ['credential_version' => '2']),
        array_replace($metadata, ['independence_group' => '']), array_replace($metadata, ['probe_token' => 'not-a-uuid']),
        array_replace($metadata, ['kind' => 'other']), array_replace($metadata, ['extra' => true]), array_diff_key($metadata, ['probe_token' => true])] as $index => $invalid) {
        expect(fn () => $class::decode(json_encode($invalid), 1))->toThrow($expected[$index]);
    }
    expect(fn () => $class::decode(json_encode($metadata), 2))->toThrow(LogicException::class);
});

test('stable decay никогда не округляет599999999 микросекунд до600 секунд', function (): void {
    $stable = new DateTimeImmutable('2026-10-29T01:00:00.999999Z');
    $row = new HealthSnapshot(HealthScope::RouteTarget, 'fixture',
        CircuitState::Closed, 1, 0, 3, null, null, $stable, null, null);
    $reducer = app(HealthReducer::class);
    $early = $reducer->reduce($row, HealthSignal::Success, 'http_200', new DateTimeImmutable('2026-10-29T01:10:00.999998Z'), 1, 0, 0, false);
    $due = $reducer->reduce($row, HealthSignal::Success, 'http_200', $stable->modify('+600 seconds'), 1, 0, 0, false);
    expect($early->openings)->toBe(3)->and($due->openings)->toBe(2);
});
