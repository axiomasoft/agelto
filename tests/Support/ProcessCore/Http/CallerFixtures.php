<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore\Http;

use App\Dto\Procurement\Network\ProviderKey;
use App\Dto\Procurement\Network\ProviderTargetDefinitions;
use App\Dto\Procurement\Platform\SourceTargetDefinition;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Services\Platforms\Eis\Csv\CsvSourceMethod;
use App\Services\Procurement\Network\Contracts\LegacyOutbound;
use App\Services\Procurement\Network\LegacyOutboundClient;
use App\Services\Procurement\Network\SourceCatalogSync;
use Illuminate\Support\Facades\DB;

final class CallerFixtures
{
    public static function platform(WireFixture $wire): Registry
    {
        config(['crawler.eis.csv_endpoint' => $wire->url('/csv')->reveal(),
            'crawler.okpd2.children_endpoint' => $wire->url('/children')->reveal(),
            'crawler.documents_allowed_hosts' => ['fixture.test'],
            'outbound.http.tls_bundles' => ['fixture.test' => $wire->root.'/cert.pem']]);
        app()->instance(LegacyOutbound::class, app()->makeWith(LegacyOutboundClient::class, ['urls' => $wire->policy()]));
        $platform = Platform::query()->firstOrCreate(['code' => 'eis'], ['name' => 'ЕИС', 'base_url' => $wire->url('/')->reveal(), 'is_active' => true]);
        $registry = Registry::query()->firstOrCreate(['platform_id' => $platform->id, 'code' => 'fz44'], ['name' => '44-ФЗ', 'settings' => []]);
        app(SourceCatalogSync::class)->sync(app(CsvSourceMethod::class)->sourceTargets());
        self::healthReady();

        return $registry;
    }

    public static function healthReady(): void
    {
        // Prior successful canary is fixture setup; the actual sends still require real owners/permits.
        foreach (['prc_route_health', 'prc_route_target_health', 'prc_target_health'] as $table) {
            DB::table($table)->update(['state' => 'closed']);
        }
        DB::table('prc_network_budgets')->update(['emission_interval_us' => 100_000]);
    }

    public static function provider(WireFixture $wire): void
    {
        app()->instance(LegacyOutbound::class, app()->makeWith(LegacyOutboundClient::class, ['urls' => $wire->policy()]));
        app(SourceCatalogSync::class)->syncProvider(new ProviderTargetDefinitions(new ProviderKey('wire'), [
            new SourceTargetDefinition('provider:wire:api', 'provider', $wire->url('/')->origin, [TransportFixtures::profile()]),
        ]));
        DB::table('prc_network_route_profile_members')->insert([
            'profile_id' => DB::table('prc_network_route_profiles')->where('profile_key', 'llm')->sole()->id,
            'route_id' => DB::table('prc_network_routes')->where('kind', 'direct')->sole()->id,
        ]);
        self::healthReady();
    }

    public static function assertAccounting(WireFixture $wire, int $sends, string $caller): void
    {
        expect($wire->receipts())->toHaveCount($sends)
            ->and(DB::table('prc_outbound_operations')->where('caller_code', $caller)->where('state', 'closed')->count())->toBe($sends)
            ->and(DB::table('prc_network_permits')->count())->toBe($sends)
            ->and(DB::table('prc_request_attempts')->whereNotNull('finished_at')->count())->toBe($sends)
            ->and(DB::table('prc_fetches')->count())->toBe(0);
    }
}
