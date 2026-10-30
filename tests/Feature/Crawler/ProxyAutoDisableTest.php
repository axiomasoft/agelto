<?php

declare(strict_types=1);

use App\Dto\Procurement\Network\RetryRoutePreference;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Enums\Procurement\Network\TransportFailure;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\User;
use App\Services\Procurement\Network\NetworkRouteCommands;
use App\Services\Procurement\Proxy\ProxyResolver;
use App\Services\Procurement\Proxy\RoutePolicy;
use Illuminate\Support\Facades\Artisan;
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

test('глобальные открытия breaker отключают прокси и убирают его из выдачи', function (): void {
    $proxy = $this->f->proxy();
    for ($i = 0; $i < 5; $i++) {
        $this->f->outcome(F::failure(TransportFailure::ProxyConnect), $proxy);
    }
    $choice = app(ProxyResolver::class)->outboundRoutes(new RoutePolicy([$proxy], $this->f->profile))
        ->select($this->f->permits->plan(), new RetryRoutePreference([], null, false, 2));
    expect($choice)->toBeInstanceOf(TransportDeferred::class)
        ->and(DB::table('prc_route_health')->where('route_id', $proxy->id->value)->value('state'))->toBe('open')
        // Health denial no longer mutates an operator's independent administrative choice.
        ->and(DB::table('prc_network_routes')->where('id', $proxy->id->value)->value('enabled'))->toBeTrue();
});

test('ручное включение сбрасывает состояние отключения', function (): void {
    Artisan::call('guard:sync-roles');
    $actor = User::factory()->create();
    $actor->assignRole(OperatorRole::class);
    try {
        $route = $this->f->proxy();
        $proxy = Proxy::factory()->create(['route_id' => $route->id->value]);
        DB::table('prc_network_routes')->where('id', $route->id->value)->update(['enabled' => false, 'disabled_at' => now(), 'disabled_reason' => 'fixture']);
        $before = DB::table('prc_route_health')->where('route_id', $route->id->value)->sole();
        app(NetworkRouteCommands::class)->reactivate($actor, $proxy, 'Проверена конфигурация');
        $changed = DB::table('prc_network_routes')->where('id', $route->id->value)->sole();
        expect($changed->enabled)->toBeTrue()->and($changed->disabled_at)->toBeNull()->and($changed->disabled_reason)->toBeNull()
            ->and((array) DB::table('prc_route_health')->where('route_id', $route->id->value)->sole())->toBe((array) $before)
            ->and(DB::table('prc_process_transitions')->where('actor_id', $actor->id)->count())->toBe(1);
    } finally {
        $actor->delete();
    }
});

test('пер-хостовое открытие breaker не отключает узел глобально', function (): void {
    $proxy = $this->f->proxy();
    for ($i = 0; $i < 5; $i++) {
        $this->f->outcome(F::failure(TransportFailure::Timeout), $proxy);
    }
    expect(DB::table('prc_route_target_health')->where('route_id', $proxy->id->value)->value('state'))->toBe('open')
        ->and(DB::table('prc_route_health')->where('route_id', $proxy->id->value)->value('state'))->toBe('closed')
        ->and(DB::table('prc_network_routes')->where('id', $proxy->id->value)->value('enabled'))->toBeTrue();
});

test('успех до порога обнуляет серию глобальных открытий', function (): void {
    $proxy = $this->f->proxy();
    $this->f->outcome(F::failure(TransportFailure::ProxyConnect), $proxy);
    $this->f->outcome(F::success(), $proxy);
    expect(DB::table('prc_route_health')->where('route_id', $proxy->id->value)->value('consecutive_failures'))->toBe(0)
        ->and(DB::table('prc_route_health')->where('route_id', $proxy->id->value)->value('state'))->toBe('closed');
});
