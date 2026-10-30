<?php

declare(strict_types=1);

use App\Dto\Procurement\Network\HttpRouteBinding;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Enums\Procurement\Network\NetworkBlockedReason;
use App\Enums\Procurement\Network\NetworkDeferralReason;
use App\Enums\Procurement\Network\TrafficClass;
use App\Exceptions\Procurement\Network\OutboundUnavailable;
use App\Services\Procurement\Network\LegacyOutboundClient;
use App\Services\Procurement\Proxy\ProxyResolver;
use App\Services\Procurement\Proxy\RoutePolicy;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\Http\PermitFixtures as F;
use Tests\Support\ProcessCore\Http\WireFixture;

beforeEach(fn () => F::clean());
afterEach(fn () => F::clean());

test('malformed catalog profile is a configuration hold before any operation or request', function (mixed $profiles): void {
    $f = new F;
    $target = DB::table('prc_source_targets')->where('id', $f->target->value)->sole();
    DB::table('prc_source_targets')->where('id', $f->target->value)
        ->update(['endpoint_policy' => json_encode(['profiles' => $profiles], JSON_THROW_ON_ERROR)]);
    $result = app(LegacyOutboundClient::class)->execute('fixture.bad_catalog', $target->platform_id, null,
        $target->target_key, $f->profile->code, $f->url, TrafficClass::Detail,
        new RoutePolicy([new HttpRouteBinding($f->route->routeId)]), ['acquisition:platform:'.$f->code], 60);
    expect($result)->toBeInstanceOf(TransportBlocked::class)
        ->and($result->reason)->toBe(NetworkBlockedReason::ConfigurationHold)
        ->and(DB::table('prc_outbound_operations')->count())->toBe(0)
        ->and(DB::table('prc_network_demands')->count())->toBe(0)
        ->and(DB::table('prc_request_attempts')->count())->toBe(0);
})->with(['noncollection' => [false], 'nonprofile' => [[7]], 'invalidprofile' => [[['code' => 'broken']]]])->group('t04', 'p26');

test('legacy адаптер выполняет реальные повторы через один owner и отдельные permits', function (): void {
    $wire = new WireFixture;
    try {
        $fixture = new F($wire);
        $platformId = DB::table('prc_source_targets')->where('id', $fixture->target->value)->value('platform_id');
        $client = app()->makeWith(LegacyOutboundClient::class, ['urls' => $wire->policy()]);
        $response = $client->request(callerCode: 'fixture.legacy_call', platformId: $platformId,
            providerKey: null, capability: 'detail', url: $wire->url('/sequence'), trafficClass: TrafficClass::Detail,
            routePolicy: new RoutePolicy([new HttpRouteBinding($fixture->route->routeId)]),
            controls: ['acquisition:platform:'.$fixture->code], deadlineSeconds: 60);
        expect($response->status())->toBe(200)
            ->and($wire->receipts())->toHaveCount(3)
            ->and(DB::table('prc_outbound_operations')->count())->toBe(1)
            ->and(DB::table('prc_outbound_operations')->value('state'))->toBe('closed')
            ->and(DB::table('prc_request_attempts')->count())->toBe(3)
            ->and(DB::table('prc_request_attempts')->whereNotNull('finished_at')->count())->toBe(3)
            ->and(DB::table('prc_network_permits')->count())->toBe(3)
            ->and(DB::table('prc_fetches')->count())->toBe(0);
    } finally {
        $wire->stop();
    }
})->group('t04', 'p26');

test('суженный лимит caller запрещает второй physical send после обрыва', function (): void {
    $wire = new WireFixture;
    try {
        $fixture = new F($wire);
        $platformId = DB::table('prc_source_targets')->where('id', $fixture->target->value)->value('platform_id');
        $client = app()->makeWith(LegacyOutboundClient::class, ['urls' => $wire->policy()]);
        expect(fn () => $client->request(callerCode: 'fixture.bounded_call', platformId: $platformId,
            providerKey: null, capability: 'detail', url: $wire->url('/sequence'), trafficClass: TrafficClass::Diagnostic,
            routePolicy: new RoutePolicy([new HttpRouteBinding($fixture->route->routeId)]),
            controls: ['acquisition:platform:'.$fixture->code], deadlineSeconds: 60, sendLimit: 1))
            ->toThrow(OutboundUnavailable::class);
        expect($wire->receipts())->toHaveCount(1)
            ->and(DB::table('prc_request_attempts')->count())->toBe(1)
            ->and(DB::table('prc_outbound_operations')->value('state'))->toBe('closed');
    } finally {
        $wire->stop();
    }
})->group('t04', 'p26');

test('первый реальный read-only caller получает составную probe вместо допуска backlog', function (): void {
    $wire = new WireFixture;
    try {
        $fixture = new F($wire);
        foreach (['prc_route_health', 'prc_route_target_health', 'prc_target_health'] as $table) {
            DB::table($table)->update(['state' => 'unknown']);
        }
        $platformId = DB::table('prc_source_targets')->where('id', $fixture->target->value)->value('platform_id');
        $client = app()->makeWith(LegacyOutboundClient::class, ['urls' => $wire->policy()]);
        $response = $client->request(callerCode: 'fixture.initial_canary', platformId: $platformId,
            providerKey: null, capability: 'detail', url: $wire->url('/ok'), trafficClass: TrafficClass::Detail,
            routePolicy: new RoutePolicy([new HttpRouteBinding($fixture->route->routeId)]),
            controls: ['acquisition:platform:'.$fixture->code], deadlineSeconds: 60);
        expect($response->status())->toBe(200)
            ->and($wire->receipts())->toHaveCount(1)
            ->and(DB::table('prc_outbound_operations')->value('kind'))->toBe('probe')
            ->and(DB::table('prc_network_demands')->value('purpose'))->toBe('probe');
        foreach (['prc_route_health', 'prc_route_target_health', 'prc_target_health'] as $table) {
            expect(DB::table($table)->value('state'))->toBe('closed');
        }
    } finally {
        $wire->stop();
    }
})->group('t03', 'p26');

test('сбой DB на границе caller откладывает зависимость и не создаёт физическую попытку', function (string $boundary, string $sqlstate): void {
    $f = new F;
    $platformId = DB::table('prc_source_targets')->where('id', $f->target->value)->value('platform_id');
    $client = app(LegacyOutboundClient::class);
    $routes = app(ProxyResolver::class);
    $failure = new PDOException('synthetic database fault');
    $failure->errorInfo = [$sqlstate];
    $armed = true;
    DB::connection()->beforeExecuting(function () use (&$armed, $failure): void {
        if ($armed) {
            $armed = false;
            throw $failure;
        }
    });
    $invoke = $boundary === 'profile' ? fn () => $routes->profile()
        : fn () => $client->request(callerCode: 'fixture.db_fault', platformId: $platformId,
            providerKey: null, capability: 'detail', url: $f->url, trafficClass: TrafficClass::Detail,
            routePolicy: new RoutePolicy([new HttpRouteBinding($f->route->routeId)]),
            controls: ['acquisition:platform:'.$f->code], deadlineSeconds: 60);
    if ($sqlstate === '08006') {
        try {
            $invoke();
            test()->fail('Dependency outage was accepted.');
        } catch (OutboundUnavailable $outage) {
            expect($outage->outcome)->toBeInstanceOf(TransportDeferred::class)
                ->and($outage->outcome->reason)->toBe(NetworkDeferralReason::DependencyUnavailable)
                ->and($outage->outcome->retryAt->value)->toBeGreaterThan(new DateTimeImmutable);
        }
    } else {
        expect($invoke)->toThrow(PDOException::class, 'synthetic database fault');
    }
    expect(DB::table('prc_outbound_operations')->count())->toBe(0)
        ->and(DB::table('prc_request_attempts')->count())->toBe(0);
})->with([['profile', '08006'], ['catalog', '08006'], ['profile', '23514'], ['catalog', '23514']])->group('t04', 'p26');
