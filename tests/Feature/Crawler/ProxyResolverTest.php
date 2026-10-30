<?php

declare(strict_types=1);

use App\Dto\Procurement\Network\NetworkRouteSelection;
use App\Dto\Procurement\Network\ProbeClaim;
use App\Dto\Procurement\Network\RetryRoutePreference;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Enums\Procurement\Network\OutboundOperationKind;
use App\Enums\Procurement\Network\TransportFailure;
use App\Services\Procurement\Network\HealthPolicy;
use App\Services\Procurement\Network\ProbeScheduler;
use App\Services\Procurement\Proxy\ProxyResolver;
use App\Services\Procurement\Proxy\RoutePolicy;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\Proxy\HealthFixtures as F;

// P2.6 keeps historical scenario identities. Explicit profiles supersede implicit geo/score
// filtering; real authorization order supersedes weighted randomness. Outcome accounting belongs
// exclusively to the permit repository, and health eligibility is separate from configuration.
beforeEach(function (): void {
    PermitFixtures::clean();
    $this->wire = new WireFixture;
    $this->f = new F($this->wire);
});
afterEach(function (): void {
    $this->wire->stop();
    PermitFixtures::clean();
});

function canonicalResolverChoice(F $f, array $bindings): mixed
{
    return app(ProxyResolver::class)->outboundRoutes(new RoutePolicy($bindings, $f->profile))
        ->select($f->permits->plan(), new RetryRoutePreference([], null, false, 2));
}

test('score использует только числовую конфигурацию весов и точек отсчёта', function (): void {
    $a = $this->f->proxy();
    $b = $this->f->proxy();
    expect(canonicalResolverChoice($this->f, [$a, $b])->binding->id->value)->toBe($a->id->value);
    $this->f->outcome(F::success(), $a);
    expect(canonicalResolverChoice($this->f, [$a, $b])->binding->id->value)->toBe($b->id->value);
});

test('score отклоняет некорректную конфигурацию', function (): void {
    config(['outbound.health.global_consecutive' => '5']);
    expect(fn () => app(HealthPolicy::class))->toThrow(InvalidArgumentException::class, 'global_consecutive');
});

test('узел из запрещённой по фильтру страны не попадает в кандидаты', function (): void {
    $allowed = $this->f->proxy();
    $excluded = $this->f->proxy();
    DB::table('prc_network_route_profile_members')->where('profile_id', $this->f->profile->value)->where('route_id', $excluded->id->value)->delete();
    expect(canonicalResolverChoice($this->f, [$allowed, $excluded])->binding->id->value)->toBe($allowed->id->value);
    expect(canonicalResolverChoice($this->f, [$excluded]))->toBeInstanceOf(TransportDeferred::class);
    expect(DB::table('prc_request_attempts')->count())->toBe(0);
});

test('требование страны без совпадений даёт NoProxyAvailableException', function (): void {
    $allowed = $this->f->proxy();
    $excluded = $this->f->proxy();
    DB::table('prc_network_route_profile_members')->where('profile_id', $this->f->profile->value)->where('route_id', $excluded->id->value)->delete();
    expect(canonicalResolverChoice($this->f, [$allowed, $excluded])->binding->id->value)->toBe($allowed->id->value);
    expect(canonicalResolverChoice($this->f, [$excluded]))->toBeInstanceOf(TransportDeferred::class);
    expect(DB::table('prc_request_attempts')->count())->toBe(0);
});

test('явная привязка не позволяет выдать подходящий, но не привязанный узел', function (): void {
    $allowed = $this->f->proxy();
    $excluded = $this->f->proxy();
    DB::table('prc_network_route_profile_members')->where('profile_id', $this->f->profile->value)->where('route_id', $excluded->id->value)->delete();
    expect(canonicalResolverChoice($this->f, [$allowed, $excluded])->binding->id->value)->toBe($allowed->id->value);
    expect(canonicalResolverChoice($this->f, [$excluded]))->toBeInstanceOf(TransportDeferred::class);
    expect(DB::table('prc_request_attempts')->count())->toBe(0);
});

test('гео-гейт применяется до явной привязки и не выдаёт запрещённую страну из набора', function (): void {
    $allowed = $this->f->proxy();
    $excluded = $this->f->proxy();
    DB::table('prc_network_route_profile_members')->where('profile_id', $this->f->profile->value)->where('route_id', $excluded->id->value)->delete();
    expect(canonicalResolverChoice($this->f, [$allowed, $excluded])->binding->id->value)->toBe($allowed->id->value);
    expect(canonicalResolverChoice($this->f, [$excluded]))->toBeInstanceOf(TransportDeferred::class);
    expect(DB::table('prc_request_attempts')->count())->toBe(0);
});

test('открытый breaker на одной цели не исключает узел на другой цели', function (): void {
    $a = $this->f->proxy();
    $b = $this->f->proxy();
    DB::table('prc_route_target_health')->where('route_id', $a->id->value)->update(['state' => 'open', 'open_until' => DB::raw("clock_timestamp() + interval '5 minutes'")]);
    expect(canonicalResolverChoice($this->f, [$a]))->toBeInstanceOf(TransportDeferred::class)
        ->and(canonicalResolverChoice($this->f, [$b])->binding->id->value)->toBe($b->id->value)
        ->and(DB::table('prc_route_health')->where('route_id', $a->id->value)->value('state'))->toBe('closed');
});

test('half-open пропускает ровно одну пробу', function (): void {
    DB::table('prc_target_health')->update(['state' => 'unknown']);
    $plan = $this->f->permits->plan();
    $route = new NetworkRouteSelection($this->f->permits->route->routeId);
    $one = $this->f->permits->context(kind: OutboundOperationKind::Probe, probeToken: LedgerFixtures::uuid());
    $two = $this->f->permits->context(kind: OutboundOperationKind::Probe, probeToken: LedgerFixtures::uuid());
    expect(app(ProbeScheduler::class)->claim($plan, $one, $route))->toBeInstanceOf(ProbeClaim::class)
        ->and(app(ProbeScheduler::class)->claim($plan, $two, $route))->toBeInstanceOf(TransportDeferred::class)
        ->and(DB::table('prc_request_attempts')->count())->toBe(0);
});

test('tie-breaker по числу отказов работает только при равном score', function (): void {
    $a = $this->f->proxy();
    $b = $this->f->proxy();
    DB::table('prc_route_target_health')->where('route_id', $a->id->value)->update(['consecutive_failures' => 4]);
    // Failure counts no longer invent a score: only actual authorization order breaks ties.
    expect(canonicalResolverChoice($this->f, [$a, $b])->binding->id->value)->toBe($a->id->value);
    $this->f->outcome(F::success(), $a);
    expect(canonicalResolverChoice($this->f, [$a, $b])->binding->id->value)->toBe($b->id->value);
});

test('пустой пул даёт NoProxyAvailableException', function (): void {
    expect(canonicalResolverChoice($this->f, []))->toBeInstanceOf(TransportDeferred::class)
        ->and(DB::table('prc_request_attempts')->count())->toBe(0);
});

test('default-режим выдаёт только сохранённый узел и помечает его источником default', function (): void {
    $profile = app(ProxyResolver::class)->profile();
    expect(array_keys($profile->bindings))->toBe([$this->f->permits->route->routeId->value])
        ->and($profile->profileId->value)->toBe(DB::table('prc_network_route_profiles')->where('profile_key', 'default')->value('id'));
});

test('configured unavailable default не переходит на direct или другой узел', function (): void {
    $a = $this->f->proxy();
    $this->f->proxy();
    DB::table('prc_network_routes')->where('id', $a->id->value)->update(['enabled' => false]);
    expect(canonicalResolverChoice($this->f, [$a]))->toBeInstanceOf(TransportDeferred::class)
        ->and(DB::table('prc_request_attempts')->count())->toBe(0);
});

test('default-режим без сохранённого узла создаёт typed direct-лизу', function (): void {
    $profile = app(ProxyResolver::class)->profile();
    expect(array_values($profile->bindings)[0]->proxy())->toBe('');
    DB::table('prc_network_route_profile_members')->where('profile_id', $profile->profileId->value)->delete();
    expect(app(ProxyResolver::class)->profile()->bindings)->toBe([]);
});

test('weighted random с инъектированным источником выбирает предсказуемый узел', function (): void {
    $a = $this->f->proxy();
    $b = $this->f->proxy();
    foreach (range(1, 3) as $ignored) {
        expect(canonicalResolverChoice($this->f, [$a, $b])->binding->id->value)->toBe($a->id->value);
    }
    $this->f->outcome(F::success(), $a);
    expect(canonicalResolverChoice($this->f, [$a, $b])->binding->id->value)->toBe($b->id->value);
});

test('reportOutcome с латентностью пишет latency_ms_avg узла (не NULL)', function (): void {
    $permit = $this->f->authorize($this->f->permits->context());
    DB::table('prc_request_attempts')->where('request_id', $permit->ticket->plan->physicalRequestId->value)->update(['started_at' => DB::raw("clock_timestamp() - interval '1 second'")]);
    $this->f->permits->repo->record($permit->ticket->plan->physicalRequestId, F::success());
    expect(DB::table('prc_request_attempts')->value('duration_ms'))->toBeGreaterThanOrEqual(1000)
        ->and(DB::table('prc_request_attempts')->value('finished_at'))->not->toBeNull();
});

test('reportOutcome на успехе сбрасывает целевой и глобальный breaker', function (): void {
    $proxy = $this->f->proxy();
    foreach (['prc_route_health', 'prc_route_target_health'] as $table) {
        DB::table($table)->where('route_id', $proxy->id->value)->update(['consecutive_failures' => 3, 'recent_openings' => 2]);
    }
    $this->f->outcome(F::success(), $proxy);
    foreach (['prc_route_health', 'prc_route_target_health'] as $table) {
        $row = DB::table($table)->where('route_id', $proxy->id->value)->sole();
        expect($row->consecutive_failures)->toBe(0)->and($row->recent_openings)->toBe(2)->and($row->state)->toBe('closed');
    }
});

test('reportOutcome целевой причины не трогает глобальный fails_count', function (): void {
    $proxy = $this->f->proxy();
    $this->f->outcome(F::failure(TransportFailure::Timeout), $proxy);
    expect(DB::table('prc_route_health')->where('route_id', $proxy->id->value)->value('consecutive_failures'))->toBe(0)
        ->and(DB::table('prc_route_target_health')->where('route_id', $proxy->id->value)->value('consecutive_failures'))->toBe(1);
});

test('reportOutcome глобальной причины не трогает целевой consecutive_failures', function (): void {
    $proxy = $this->f->proxy();
    $this->f->outcome(F::failure(TransportFailure::ProxyConnect), $proxy);
    expect(DB::table('prc_route_health')->where('route_id', $proxy->id->value)->value('consecutive_failures'))->toBe(1)
        ->and(DB::table('prc_route_target_health')->where('route_id', $proxy->id->value)->value('consecutive_failures'))->toBe(0);
});

test('reportOutcome direct-лизы не создаёт health-строк', function (): void {
    $count = DB::table('prc_route_health')->count();
    $this->f->outcome(F::failure());
    expect(DB::table('prc_route_health')->count())->toBe($count)
        ->and(DB::table('prc_route_health')->sole()->consecutive_failures)->toBe(0)
        ->and(DB::table('prc_route_target_health')->sole()->consecutive_failures)->toBe(1);
});

test('политика required_country=RU не выдаёт узел другой страны ни при одном значении случайности', function (): void {
    $excluded = $this->f->proxy();
    DB::table('prc_network_route_profile_members')->where('profile_id', $this->f->profile->value)->where('route_id', $excluded->id->value)->delete();
    foreach (range(1, 3) as $ignored) {
        expect(canonicalResolverChoice($this->f, [$excluded]))->toBeInstanceOf(TransportDeferred::class);
    }
    expect(DB::table('prc_request_attempts')->count())->toBe(0);
});

test('узел без страны не проходит скоуп с непустым required_country', function (): void {
    $excluded = $this->f->proxy();
    DB::table('prc_network_route_profile_members')->where('profile_id', $this->f->profile->value)->where('route_id', $excluded->id->value)->delete();
    foreach (range(1, 3) as $ignored) {
        expect(canonicalResolverChoice($this->f, [$excluded]))->toBeInstanceOf(TransportDeferred::class);
    }
    expect(DB::table('prc_request_attempts')->count())->toBe(0);
});

test('требование профиля, противоречащее required_country скоупа, даёт NoProxyAvailableException', function (): void {
    $excluded = $this->f->proxy();
    DB::table('prc_network_route_profile_members')->where('profile_id', $this->f->profile->value)->where('route_id', $excluded->id->value)->delete();
    foreach (range(1, 3) as $ignored) {
        expect(canonicalResolverChoice($this->f, [$excluded]))->toBeInstanceOf(TransportDeferred::class);
    }
    expect(DB::table('prc_request_attempts')->count())->toBe(0);
});
