<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Network\Control\ProxyNodeConfiguration;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Services\Procurement\Proxyma\Migration\HistoricalCatalog;
use Illuminate\Support\Facades\DB;
use Proxyma\Domain\Execution\Uuid;

/** Данные исходного каталога fixtures; не содержит runtime admission/health/transport. */
final class HistoricalRouteCatalog
{
    public function ensureProfile(string $key, ?string $initialRoute = null): void
    {
        HistoricalNetworkFixtures::profile($key, $initialRoute === null ? [] : [$initialRoute]);
    }

    public function updateProfile(string $key, int $version, array $routes, mixed ...$audit): int
    {
        $id = HistoricalNetworkFixtures::profile($key);
        DB::table('prc_network_route_profile_members')->where('profile_id', $id)->delete();
        foreach ($routes as $route) {
            DB::table('prc_network_route_profile_members')->insert(['profile_id' => $id, 'route_id' => $route]);
        }
        DB::table('prc_network_route_profiles')->where('profile_key', $key)->update(['policy_version' => $version + 1]);

        return $version + 1;
    }

    public function storeProxy(?int $id, ProxyNodeConfiguration $configuration, mixed ...$audit): object
    {
        if ($id !== null) {
            throw new \LogicException('Historical fixture only creates immutable source rows.');
        }

        return HistoricalNetworkFixtures::proxy(['service' => $configuration->service, 'host' => $configuration->host,
            'port' => $configuration->port, 'country' => $configuration->country]);
    }

    public function profile(?ProcessUuid $id, string $key): ?ProcessUuid
    {
        $row = HistoricalCatalog::profile($id, $key);

        return $row === null ? null : new ProcessUuid($row->string('id'));
    }

    public function ensureDirectRoute(string $egressId, ?string $independenceGroup): string
    {
        HistoricalNetworkFixtures::synchronize();
        $existing = DB::table('prc_network_routes')->where('route_key', 'direct:'.$egressId)->value('id');
        if (is_string($existing)) {
            return $existing;
        }
        $id = Uuid::random()->value;
        DB::table('prc_network_routes')->insert(['id' => $id, 'route_key' => 'direct:'.$egressId, 'kind' => 'direct',
            'egress_id' => $egressId, 'independence_group' => $independenceGroup, 'enabled' => true, 'credential_version' => 1]);

        return $id;
    }

    public function bindings(ProcessUuid $profile): array
    {
        return HistoricalCatalog::bindings($profile);
    }
}
