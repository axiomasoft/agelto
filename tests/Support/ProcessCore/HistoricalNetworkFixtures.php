<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Platform\Definition\CanonicalOrigin;
use App\Services\Procurement\Proxyma\Caller\CallerSendFacts;
use Illuminate\Support\Facades\DB;
use Proxyma\Domain\Execution\Uuid;
use Tests\Support\TestEnvironmentGuard;

/** Только исходные строки upgrade/replay fixtures; здесь нет admission, reducer или transport. */
final class HistoricalNetworkFixtures
{
    private static function guard(): void
    {
        TestEnvironmentGuard::assertBootstrapTestDatabase(app());
        if (getenv('PROCESS_CORE_TEST') !== '1' || DB::selectOne('SELECT current_database() AS db')->db !== 'agelto_test') {
            throw new \LogicException('Historical fixtures require isolated agelto_test.');
        }
    }

    /** @param list<string>|null $routes */
    public static function profile(string $key, ?array $routes = null): string
    {
        self::guard();
        $id = DB::table('prc_network_route_profiles')->where('profile_key', $key)->value('id');
        if (! is_string($id)) {
            $id = Uuid::random()->value;
            DB::table('prc_network_route_profiles')->insert(['id' => $id, 'profile_key' => $key, 'policy_version' => 1]);
            foreach ($routes ?? [] as $route) {
                DB::table('prc_network_route_profile_members')->insert(['profile_id' => $id, 'route_id' => $route]);
            }
        }

        return $id;
    }

    public static function synchronize(): void
    {
        self::guard();
        if (DB::getSchemaBuilder()->hasTable('prc_network_authorities') && CallerSendFacts::active()) {
            foreach (DB::table('prc_proxyma_target_links as l')->join('prc_source_targets as s', 's.id', '=', 'l.source_target_id')
                ->join('pxm_targets as t', 't.id', '=', 'l.target_id')->whereColumn('s.canonical_origin', 't.canonical_origin')
                ->get(['s.id', 's.policy_version']) as $source) {
                DB::table('prc_proxyma_target_links')->where('source_target_id', $source->id)->update(['source_revision' => $source->policy_version]);
            }

            return;
        }
        $route = DB::table('prc_network_routes')->where('kind', 'direct')->value('id');
        if (! is_string($route)) {
            $route = Uuid::random()->value;
            DB::table('prc_network_routes')->insert(['id' => $route, 'route_key' => 'direct:fixture', 'kind' => 'direct',
                'egress_id' => 'nat:fixture', 'enabled' => true, 'credential_version' => 1]);
        }
        self::profile(config()->string('outbound.routing.default_profile'), [$route]);
        self::profile(config()->string('outbound.routing.provider_profile'));
        $routes = DB::table('prc_network_routes')->pluck('id')->all();
        foreach (DB::table('prc_source_targets')->whereNull('retired_at')->get() as $target) {
            $origin = CanonicalOrigin::fromUrl($target->canonical_origin);
            foreach ([$origin->budgetScopeKey(), $origin->probeScopeKey(), ...array_map($origin->routeScopeKey(...), $routes)] as $scope) {
                DB::table('prc_network_budgets')->insertOrIgnore(['id' => Uuid::random()->value, 'scope_key' => $scope,
                    'emission_interval_us' => 100_000, 'burst' => 1, 'max_inflight' => 2, 'policy_version' => 1]);
            }
            DB::table('prc_target_health')->insertOrIgnore(['id' => Uuid::random()->value, 'target_id' => $target->id,
                'state' => 'closed', 'health_epoch' => 1, 'state_version' => 1, 'availability' => 'available',
                'recent_openings' => 0]);
            foreach ($routes as $id) {
                DB::table('prc_route_health')->insertOrIgnore(['id' => Uuid::random()->value, 'route_id' => $id,
                    'state' => 'closed', 'health_epoch' => 1, 'state_version' => 1, 'recent_openings' => 0, 'consecutive_failures' => 0]);
                DB::table('prc_route_target_health')->insertOrIgnore(['id' => Uuid::random()->value, 'route_id' => $id, 'target_id' => $target->id,
                    'state' => 'closed', 'health_epoch' => 1, 'state_version' => 1, 'recent_openings' => 0, 'consecutive_failures' => 0]);
            }
        }
    }

    /** @param array<string, mixed> $attributes */
    public static function proxy(array $attributes = []): object
    {
        self::guard();
        $route = Uuid::random()->value;
        DB::table('prc_network_routes')->insert(['id' => $route, 'route_key' => 'proxy:'.$route, 'kind' => 'proxy',
            'egress_id' => 'proxy:fixture', 'enabled' => true, 'credential_version' => 1]);
        $id = DB::table('prc_proxies')->insertGetId(array_replace(['route_id' => $route, 'service' => 'historical fixture',
            'host' => 'proxy.fixture.test', 'port' => 3128, 'country' => 'RU', 'scopes' => '[]'], $attributes));

        return DB::table('prc_proxies')->where('id', $id)->sole();
    }

    public static function directProfile(): string
    {
        self::synchronize();

        return self::profile('direct', [DB::table('prc_network_routes')->where('kind', 'direct')->sole()->id]);
    }

    /** @param list<object> $proxies */
    public static function profileForProxies(array $proxies): string
    {
        self::guard();

        return self::profile('fixture:'.Uuid::random()->value, array_map(static fn (object $proxy): string => $proxy->route_id, $proxies));
    }
}
